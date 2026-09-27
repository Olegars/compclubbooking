<?php

namespace App\Services\Faceit;

use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\FaceitIdentity;
use App\Models\FaceitMatch;
use App\Models\FaceitMatchPlayer;
use App\Models\FaceitWebhookEvent;
use App\Models\Game;
use App\Models\User;
use App\Services\ClubFeatureService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class FaceitIdentityService
{
    public function __construct(
        private readonly ClubFeatureService $features,
        private readonly FaceitClient $client,
    ) {
    }

    public function mode(?int $clubId): string
    {
        if (! $this->features->enabled($clubId, 'faceit')) {
            return 'off';
        }
        $mode = (string) $this->features->setting($clubId, 'faceit', 'mode');

        return in_array($mode, ['identity', 'hub'], true) ? $mode : 'off';
    }

    public function clubIdForUser(User $user): int
    {
        $fromUser = (int) ($this->features->clubIdForUser($user) ?? 0);
        if ($fromUser > 0) {
            return $fromUser;
        }

        return (int) (Club::query()->orderBy('id')->value('id') ?? 0);
    }

    public function forbidsClubSteam(Game $game): bool
    {
        $hay = mb_strtolower(trim($game->title.' '.(string) $game->exe_path));

        return str_contains($hay, 'faceit');
    }

    /**
     * Блок шелла. Без HTTP: poll не ходит в open.faceit.com.
     *
     * @return array<string, mixed>|null
     */
    public function shellBlock(?User $user, ?int $clubId, ?Booking $booking = null): ?array
    {
        if ($this->mode($clubId) === 'off' || ! $user) {
            return null;
        }
        $row = $this->identity($user);
        $banned = $row?->isBanned() ?? false;
        $linked = $row !== null;

        return [
            'enabled' => true,
            'linked' => $linked,
            'nickname' => $row?->nickname,
            'skill_level' => $linked ? $row->skill_level : null,
            'elo' => $linked ? $row->elo : null,
            'banned' => $banned,
            'steam64' => $row?->steam_id_64,
            'hint' => $banned ? 'FACEIT бан' : ($linked ? null : 'Привяжи FACEIT в ЛК'),
            'profile_path' => '/account/profile',
            'synced_at' => $row?->synced_at?->toIso8601String(),
            'stack' => $booking ? $this->stackFor($booking) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cabinet(User $user): array
    {
        $clubId = $this->clubIdForUser($user);
        $mode = $this->mode($clubId);
        $row = $this->identity($user);
        if ($mode === 'off') {
            return ['mode' => 'off'];
        }

        return [
            'mode' => $mode,
            'linked' => $row !== null,
            'nickname' => $row?->nickname,
            'avatar_url' => $row?->avatar_url,
            'skill_level' => $row?->skill_level,
            'elo' => $row?->elo,
            'kd' => $row?->kd,
            'win_rate' => $row?->win_rate,
            'banned' => $row?->isBanned() ?? false,
            'steam64' => $row?->steam_id_64,
            'stale_label' => $this->staleLabel($row),
            'hub_url' => $mode === 'hub' ? $this->hubUrl($clubId) : null,
            'hub_id' => $mode === 'hub' ? $this->hubId($clubId) : null,
        ];
    }

    public function beginOauth(User $user): string
    {
        $clubId = $this->clubIdForUser($user);
        if ($this->mode($clubId) === 'off') {
            throw new RuntimeException('FACEIT в клубе выключен');
        }
        if (! $this->client->oauthConfigured()) {
            throw new RuntimeException('FACEIT Connect не настроен');
        }
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(16));
        session([
            'faceit_oauth_state' => $state,
            'faceit_oauth_verifier' => $verifier,
        ]);

        return $this->client->authorizeUrl($state, $challenge);
    }

    public function completeOauth(User $user, string $code, string $state): FaceitIdentity
    {
        $expected = (string) session('faceit_oauth_state', '');
        $verifier = (string) session('faceit_oauth_verifier', '');
        session()->forget(['faceit_oauth_state', 'faceit_oauth_verifier']);
        if ($expected === '' || ! hash_equals($expected, $state) || $verifier === '') {
            throw new RuntimeException('Сессия FACEIT истекла, начните привязку заново');
        }
        if ($this->mode($this->clubIdForUser($user)) === 'off') {
            throw new RuntimeException('FACEIT в клубе выключен');
        }
        $token = $this->client->exchangeCode($code, $verifier);
        $access = (string) ($token['access_token'] ?? '');
        if ($access === '') {
            throw new RuntimeException('FACEIT не выдал код входа');
        }
        $info = $this->client->userinfo($access);
        unset($token, $access);
        $playerId = (string) ($info['sub'] ?? $info['player_id'] ?? $info['guid'] ?? '');
        if ($playerId === '') {
            throw new RuntimeException('FACEIT не вернул player_id');
        }
        $profile = $this->client->player($playerId);
        $profile['steam_id_64'] = $profile['steam_id_64']
            ?? ($info['steam_id_64'] ?? $info['steam_id'] ?? null);

        return $this->claim($user, $playerId, $profile, $this->banUntil($this->client->bans($playerId)), $this->statsOf($playerId));
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array{kd:?float,win_rate:?float}  $stats
     */
    public function claim(User $user, string $playerId, array $profile, ?CarbonImmutable $bannedUntil = null, array $stats = []): FaceitIdentity
    {
        $taken = FaceitIdentity::query()
            ->where('faceit_player_id', $playerId)
            ->where('user_id', '!=', $user->id)
            ->exists();
        if ($taken) {
            throw new RuntimeException('Этот FACEIT уже привязан к другому аккаунту клуба');
        }
        $cs2 = is_array($profile['games']['cs2'] ?? null) ? $profile['games']['cs2'] : [];
        $skill = isset($cs2['skill_level']) ? (int) $cs2['skill_level'] : null;
        if ($skill !== null) {
            $skill = max(1, min(10, $skill));
        }
        $steam = (string) ($profile['steam_id_64'] ?? $cs2['game_player_id'] ?? '');

        $row = FaceitIdentity::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'faceit_player_id' => $playerId,
                'nickname' => (string) ($profile['nickname'] ?? ''),
                'avatar_url' => $profile['avatar'] ?? $profile['avatar_url'] ?? null,
                'steam_id_64' => $steam !== '' ? $steam : null,
                'skill_level' => $skill,
                'elo' => isset($cs2['faceit_elo']) ? (int) $cs2['faceit_elo'] : null,
                'game_id' => 'cs2',
                'kd' => $stats['kd'] ?? null,
                'win_rate' => $stats['win_rate'] ?? null,
                'banned_until' => $bannedUntil,
                'synced_at' => now(),
                'rate_limited_at' => null,
            ],
        );
        if ($steam !== '') {
            \App\Models\UserIdentity::query()->updateOrCreate(
                ['user_id' => $user->id, 'provider' => 'steam'],
                ['external_id' => $steam, 'unlinked_at' => null, 'purge_after' => null],
            );
        }
        if ($skill !== null && $skill >= 10) {
            try {
                app(\App\Services\BattlePassService::class)->completeByCode($user, 'faceit_10', $this->clubIdForUser($user));
            } catch (\Throwable) {
            }
        }

        return $row;
    }

    public function unlink(User $user): void
    {
        FaceitIdentity::query()->where('user_id', $user->id)->delete();
    }

    public function sync(FaceitIdentity $row): void
    {
        try {
            $profile = $this->client->player($row->faceit_player_id);
            $banned = $this->banUntil($this->client->bans($row->faceit_player_id));
            $stats = ['kd' => null, 'win_rate' => null];
            try {
                $stats = $this->parseStats($this->client->stats($row->faceit_player_id));
            } catch (FaceitRateLimited $e) {
                throw $e;
            } catch (\Throwable) {
            }
            $this->claim($row->user, $row->faceit_player_id, $profile, $banned, $stats);
        } catch (FaceitRateLimited $e) {
            $row->forceFill(['rate_limited_at' => now()])->save();
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{duplicate: bool, match_id: ?string}
     */
    public function ingestWebhook(array $body): array
    {
        $eventId = (string) ($body['event_id'] ?? $body['transaction_id'] ?? '');
        $event = (string) ($body['event'] ?? $body['type'] ?? '');
        if ($eventId === '' || $event === '') {
            throw new RuntimeException('Нет event_id');
        }
        if (FaceitWebhookEvent::query()->where('event_id', $eventId)->exists()) {
            return ['duplicate' => true, 'match_id' => null];
        }
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : $body;
        $matchId = (string) ($payload['id'] ?? $payload['match_id'] ?? '');
        FaceitWebhookEvent::query()->create([
            'event_id' => $eventId,
            'event_name' => $event,
            'match_id' => $matchId !== '' ? $matchId : null,
            'payload' => $body,
        ]);
        if ($matchId !== '' && str_starts_with($event, 'match_')) {
            $this->upsertMatch($event, $matchId, $payload);
        }

        return ['duplicate' => false, 'match_id' => $matchId !== '' ? $matchId : null];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function overlayUnlessClanWar(?Computer $computer, bool $clanWarLive): ?array
    {
        if ($clanWarLive || ! $computer) {
            return null;
        }
        if ($this->mode($computer->club_id ? (int) $computer->club_id : null) !== 'hub') {
            return null;
        }

        return $this->tvOverlay($computer);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function tvOverlay(Computer $computer): ?array
    {
        $clubId = $computer->club_id ? (int) $computer->club_id : 0;
        if ($clubId < 1) {
            return null;
        }
        $userIds = Booking::query()
            ->where('status', 'active')
            ->whereIn('computer_id', Computer::query()->where('club_id', $clubId)->select('id'))
            ->pluck('user_id');
        $playerIds = FaceitIdentity::query()->whereIn('user_id', $userIds)->pluck('faceit_player_id');
        if ($playerIds->isEmpty()) {
            return null;
        }
        $match = FaceitMatch::query()
            ->whereIn('status', ['ready', 'ongoing', 'configured'])
            ->whereHas('players', fn ($q) => $q->whereIn('faceit_player_id', $playerIds))
            ->orderByDesc('id')
            ->first();
        if (! $match) {
            return null;
        }
        $factions = $match->players()
            ->get()
            ->groupBy(fn (FaceitMatchPlayer $p) => $p->faction ?: 'team')
            ->map(fn ($group, $name) => [
                'name' => (string) $name,
                'elo' => (int) round((float) $group->avg('elo_before')),
            ])
            ->values()
            ->all();

        return [
            'id' => $match->match_id,
            'map' => $match->map,
            'status' => $match->status,
            'factions' => $factions,
        ];
    }

    public function hubId(?int $clubId): string
    {
        $fromFeature = trim((string) $this->features->setting($clubId, 'faceit', 'hub_id'));
        if ($fromFeature !== '') {
            return $fromFeature;
        }

        return trim((string) config('services.faceit.hub_id'));
    }

    public function hubUrl(?int $clubId): ?string
    {
        $id = $this->hubId($clubId);
        if ($id === '') {
            return null;
        }
        $fallback = 'https://www.faceit.com/en/hub/'.$id;
        if (! $this->client->configured()) {
            return $fallback;
        }

        try {
            return Cache::remember('faceit:hub:'.$id, 6 * 3600, function () use ($id, $fallback) {
                $hub = $this->client->hub($id);
                $url = (string) ($hub['faceit_url'] ?? '');

                return $url !== '' ? $url : $fallback;
            });
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * @return array{kd:?float,win_rate:?float}
     */
    private function statsOf(string $playerId): array
    {
        try {
            return $this->parseStats($this->client->stats($playerId));
        } catch (FaceitRateLimited $e) {
            throw $e;
        } catch (\Throwable) {
            return ['kd' => null, 'win_rate' => null];
        }
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array{kd:?float,win_rate:?float}
     */
    private function parseStats(array $stats): array
    {
        $life = is_array($stats['lifetime'] ?? null) ? $stats['lifetime'] : [];
        $kd = $life['Average K/D Ratio'] ?? $life['K/D Ratio'] ?? null;
        $win = $life['Win Rate %'] ?? $life['Win Rate'] ?? null;

        return [
            'kd' => is_numeric($kd) ? round((float) $kd, 2) : null,
            'win_rate' => is_numeric($win) ? round((float) $win, 2) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $bans
     */
    private function banUntil(array $bans): ?CarbonImmutable
    {
        $items = $bans['items'] ?? $bans;
        if (! is_array($items)) {
            return null;
        }
        foreach ($items as $ban) {
            if (! is_array($ban)) {
                continue;
            }
            $ends = $ban['ends_at'] ?? null;
            if ($ends === null || $ends === '') {
                return CarbonImmutable::now()->addYears(100);
            }
            try {
                $at = CarbonImmutable::parse((string) $ends);
            } catch (\Throwable) {
                continue;
            }
            if ($at->isFuture()) {
                return $at;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertMatch(string $event, string $matchId, array $payload): void
    {
        $status = match (true) {
            str_contains($event, 'finished') => 'finished',
            str_contains($event, 'aborted'), str_contains($event, 'cancelled') => 'cancelled',
            str_contains($event, 'ready') => 'ready',
            str_contains($event, 'demo') => 'demo',
            default => 'configured',
        };
        $map = $payload['map'] ?? data_get($payload, 'voting.map.pick') ?? data_get($payload, 'game.map');
        $demo = $payload['demo_url'] ?? null;
        $existing = FaceitMatch::query()->where('match_id', $matchId)->first();
        $keepStatus = $status === 'demo' ? ($existing->status ?? 'finished') : $status;
        $match = FaceitMatch::query()->updateOrCreate(
            ['match_id' => $matchId],
            [
                'hub_id' => $payload['hub_id'] ?? data_get($payload, 'competition_id'),
                'championship_id' => $payload['championship_id'] ?? ($existing?->championship_id),
                'status' => $keepStatus,
                'map' => is_string($map) ? $map : ($existing?->map),
                'started_at' => $status === 'ready' ? now() : ($existing?->started_at),
                'finished_at' => $status === 'finished' ? now() : ($existing?->finished_at),
                'payload' => $payload,
                'demo_url' => is_string($demo) ? $demo : ($existing?->demo_url),
            ],
        );
        $this->syncRoster($match, $payload);
        if ($status === 'finished') {
            $this->refreshRosterElo($match);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncRoster(FaceitMatch $match, array $payload): void
    {
        $teams = $payload['teams'] ?? [];
        if (! is_array($teams)) {
            return;
        }
        foreach ($teams as $team) {
            if (! is_array($team)) {
                continue;
            }
            $faction = (string) ($team['name'] ?? $team['faction'] ?? 'team');
            $roster = $team['roster'] ?? $team['players'] ?? [];
            if (! is_array($roster)) {
                continue;
            }
            foreach ($roster as $player) {
                if (! is_array($player)) {
                    continue;
                }
                $pid = (string) ($player['player_id'] ?? $player['id'] ?? '');
                if ($pid === '') {
                    continue;
                }
                $identity = FaceitIdentity::query()->where('faceit_player_id', $pid)->first();
                FaceitMatchPlayer::query()->updateOrCreate(
                    ['faceit_match_id' => $match->id, 'faceit_player_id' => $pid],
                    [
                        'user_id' => $identity?->user_id,
                        'faction' => $faction,
                        'elo_before' => $identity?->elo ?? (isset($player['elo']) ? (int) $player['elo'] : null),
                    ],
                );
            }
        }
    }

    private function refreshRosterElo(FaceitMatch $match): void
    {
        foreach ($match->players()->get() as $player) {
            $identity = FaceitIdentity::query()->where('faceit_player_id', $player->faceit_player_id)->first();
            if (! $identity || ! $identity->user) {
                continue;
            }
            try {
                $this->sync($identity);
            } catch (FaceitRateLimited) {
                return;
            } catch (\Throwable) {
                continue;
            }
            $identity->refresh();
            $player->forceFill(['elo_after' => $identity->elo])->save();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stackFor(Booking $booking): ?array
    {
        if (! $booking->booking_group_id) {
            return null;
        }
        $mates = Booking::query()
            ->with(['user', 'computer'])
            ->where('booking_group_id', $booking->booking_group_id)
            ->where('status', 'active')
            ->get();
        if ($mates->count() < 2) {
            return null;
        }
        $rows = FaceitIdentity::query()->whereIn('user_id', $mates->pluck('user_id'))->get()->keyBy('user_id');
        if ($rows->count() !== $mates->count()) {
            return null;
        }
        if ($rows->contains(fn (FaceitIdentity $row) => $row->isBanned())) {
            return null;
        }
        $names = $mates->map(fn (Booking $b) => (string) ($b->computer?->name ?: 'ПК'))->all();
        $elo = (int) round((float) $rows->avg('elo'));

        return [
            'pcs' => $names,
            'elo' => $elo,
            'label' => 'Стек FACEIT: '.implode(', ', $names).', Elo ~'.$elo,
        ];
    }

    private function identity(User $user): ?FaceitIdentity
    {
        if (! Schema::hasTable('faceit_identities')) {
            return null;
        }

        return FaceitIdentity::query()->where('user_id', $user->id)->first();
    }

    private function staleLabel(?FaceitIdentity $row): ?string
    {
        if (! $row?->synced_at) {
            return null;
        }
        $mins = max(0, (int) $row->synced_at->diffInMinutes(now()));

        return 'обновлено '.$mins.' мин назад';
    }
}
