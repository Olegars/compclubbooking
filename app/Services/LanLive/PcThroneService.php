<?php

namespace App\Services\LanLive;

use App\Models\Booking;
use App\Models\Computer;
use App\Models\PcThrone;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * King of the Hill: daily frag/K/D record on a specific PC.
 */
class PcThroneService
{
    public const MIN_KILLS = 3;

    public const SESSION_TTL = 28800;

    /**
     * @param  array<string, mixed>  $snap
     */
    public function observe(Computer $computer, User $user, Booking $booking, array $snap): ?PcThrone
    {
        $event = strtolower((string) ($snap['event'] ?? ''));
        if (! in_array($event, ['kill', 'death', 'match_win', 'match_loss', 'round_win', 'round_loss'], true)) {
            return $this->forComputer($computer);
        }

        $game = ($snap['game'] ?? '') === 'dota' ? 'dota' : 'cs2';
        $stats = $this->sessionStats((int) $booking->id, $game);
        if ($event === 'kill') {
            $stats['kills']++;
        } elseif ($event === 'death') {
            $stats['deaths']++;
        } elseif (in_array($event, ['match_win', 'round_win'], true)) {
            $stats['wins']++;
        } elseif (in_array($event, ['match_loss', 'round_loss'], true)) {
            $stats['losses']++;
        }
        $nick = trim((string) ($snap['player_name'] ?? ''));
        if ($nick !== '') {
            $stats['player_name'] = mb_substr($nick, 0, 48);
        }
        $stats['game'] = $game;
        $this->putSession((int) $booking->id, $stats);

        if ((int) $stats['kills'] < self::MIN_KILLS) {
            return $this->forComputer($computer);
        }

        return $this->maybeCrown($computer, $user, $booking, $stats, $snap);
    }

    public function forComputer(?Computer $computer): ?PcThrone
    {
        if (! $computer) {
            return null;
        }

        return PcThrone::query()
            ->where('computer_id', $computer->id)
            ->whereDate('recorded_on', now()->toDateString())
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(?Computer $computer, ?User $viewer = null): ?array
    {
        $king = $this->forComputer($computer);
        if (! $king) {
            return null;
        }

        $kd = number_format((float) $king->kd, 2, '.', '');
        $game = $king->game === 'dota' ? 'Dota' : 'CS2';
        $games = (int) $king->wins + (int) $king->losses;
        $wr = $games > 0 ? (int) round(100 * (int) $king->wins / $games) : null;
        $line = 'King: '.$king->nickname.' · '.$king->kills.' фраг · K/D '.$kd
            .($wr !== null ? ' · WR '.$king->wins.'-'.$king->losses : '');

        $challenge = null;
        if ($viewer && (int) $viewer->id !== (int) $king->user_id) {
            $challenge = 'Сможешь превзойти рекорд King? '.$king->nickname
                .' — '.$king->kills.' фраг '.$game
                .($wr !== null ? ', винрейт '.$wr.'%' : '')
                .' на этом ПК сегодня.';
        }

        return [
            'has_king' => true,
            'user_id' => (int) $king->user_id,
            'nickname' => $king->nickname,
            'avatar_url' => $this->avatarUrl($king->avatar),
            'game' => $king->game,
            'metric' => $king->metric,
            'kills' => (int) $king->kills,
            'deaths' => (int) $king->deaths,
            'wins' => (int) $king->wins,
            'losses' => (int) $king->losses,
            'kd' => (float) $king->kd,
            'winrate' => $wr,
            'line' => $line,
            'challenge' => $challenge,
            'mine' => $viewer ? (int) $viewer->id === (int) $king->user_id : false,
        ];
    }

    public function resetToday(Computer $computer): bool
    {
        $deleted = PcThrone::query()
            ->where('computer_id', $computer->id)
            ->whereDate('recorded_on', now()->toDateString())
            ->delete();

        return $deleted > 0;
    }

    /**
     * @param  iterable<int, object|array>  $computers
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function decorateComputers(iterable $computers): \Illuminate\Support\Collection
    {
        $rows = collect($computers);
        $ids = $rows->map(fn ($pc) => (int) (is_array($pc) ? ($pc['id'] ?? 0) : ($pc->id ?? 0)))
            ->filter()
            ->values()
            ->all();
        if ($ids === []) {
            return $rows;
        }
        $kings = PcThrone::query()
            ->whereIn('computer_id', $ids)
            ->whereDate('recorded_on', now()->toDateString())
            ->get()
            ->keyBy('computer_id');

        return $rows->map(function ($pc) use ($kings) {
            $id = (int) (is_array($pc) ? ($pc['id'] ?? 0) : ($pc->id ?? 0));
            $king = $kings->get($id);
            if (is_array($pc)) {
                $pc['king_nick'] = $king?->nickname;
                $pc['king_kills'] = $king?->kills;
                $pc['king_line'] = $king
                    ? ($king->nickname.' · '.$king->kills.' фраг')
                    : null;

                return $pc;
            }
            $pc->king_nick = $king?->nickname;
            $pc->king_kills = $king?->kills;
            $pc->king_line = $king
                ? ($king->nickname.' · '.$king->kills.' фраг')
                : null;

            return $pc;
        });
    }

    /**
     * @param  array{kills:int,deaths:int,wins:int,losses:int,game:string,player_name?:string}  $stats
     * @param  array<string, mixed>  $snap
     */
    private function maybeCrown(Computer $computer, User $user, Booking $booking, array $stats, array $snap = []): PcThrone
    {
        $kd = round($stats['kills'] / max(1, $stats['deaths']), 2);
        $games = (int) $stats['wins'] + (int) $stats['losses'];
        $wr = $games >= 2 ? ((int) $stats['wins'] / $games) : 0.0;
        $today = now()->toDateString();
        $existing = $this->forComputer($computer);
        $existingGames = $existing ? ((int) $existing->wins + (int) $existing->losses) : 0;
        $existingWr = $existingGames >= 2
            ? ((int) $existing->wins / $existingGames)
            : 0.0;

        $byKills = ! $existing || $stats['kills'] > (int) $existing->kills
            || ($stats['kills'] === (int) $existing->kills && $kd > (float) $existing->kd);
        $byWinrate = $games >= 2 && (int) $stats['kills'] >= self::MIN_KILLS
            && $wr > $existingWr + 0.001;
        $better = $byKills || $byWinrate;
        $metric = $byWinrate && ! $byKills ? 'winrate' : ($byKills && $existing && $stats['kills'] === (int) $existing->kills ? 'kd' : 'kills');

        if (! $better) {
            return $existing;
        }

        $nick = trim((string) ($stats['player_name'] ?? $snap['player_name'] ?? ''));
        if ($nick === '' || mb_strlen($nick) < 2) {
            $nick = trim((string) $user->name) ?: ('Игрок #'.$user->id);
        }

        return PcThrone::query()->updateOrCreate(
            [
                'computer_id' => $computer->id,
                'recorded_on' => $today,
            ],
            [
                'club_id' => (int) ($computer->club_id ?? 0),
                'user_id' => $user->id,
                'booking_id' => $booking->id,
                'nickname' => mb_substr($nick, 0, 48),
                'avatar' => $user->avatar ?: 'avatar_1.png',
                'game' => $stats['game'],
                'metric' => $metric,
                'kills' => $stats['kills'],
                'deaths' => $stats['deaths'],
                'wins' => $stats['wins'],
                'losses' => $stats['losses'],
                'kd' => $kd,
            ]
        );
    }

    /**
     * @return array{kills:int,deaths:int,wins:int,losses:int,game:string,player_name?:string}
     */
    private function sessionStats(int $bookingId, string $game): array
    {
        $row = Cache::get($this->sessionKey($bookingId));
        if (! is_array($row)) {
            return ['kills' => 0, 'deaths' => 0, 'wins' => 0, 'losses' => 0, 'game' => $game];
        }

        return [
            'kills' => (int) ($row['kills'] ?? 0),
            'deaths' => (int) ($row['deaths'] ?? 0),
            'wins' => (int) ($row['wins'] ?? 0),
            'losses' => (int) ($row['losses'] ?? 0),
            'game' => (string) ($row['game'] ?? $game),
            'player_name' => (string) ($row['player_name'] ?? ''),
        ];
    }

    /**
     * @param  array{kills:int,deaths:int,wins:int,losses:int,game:string}  $stats
     */
    private function putSession(int $bookingId, array $stats): void
    {
        Cache::put($this->sessionKey($bookingId), $stats, self::SESSION_TTL);
    }

    private function sessionKey(int $bookingId): string
    {
        return 'throne:sess:'.$bookingId;
    }

    private function avatarUrl(?string $avatar): string
    {
        $name = trim((string) $avatar);
        if ($name === '') {
            $name = 'avatar_1.png';
        }
        if (! str_contains($name, '.')) {
            $name .= '.png';
        }

        return url('/images/avatars/'.$name);
    }
}
