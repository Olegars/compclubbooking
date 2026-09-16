<?php

namespace App\Services\LanLive;

use App\Models\ArenaDuel;
use App\Models\ArenaDuelParticipant;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\LanLfgQueue;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ClanWarService;
use App\Services\ClubFeatureService;
use App\Services\KitchenOrderPrintService;
use App\Services\Light\LightControlService;
use App\Support\OrderChannel;
use App\Support\OrderDeliveryTarget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ArenaDuelService
{
    public const PRESETS = [100, 250, 500];

    public const LEGAL_NOTICE = 'Взнос — плата за участие в открытом соревновании мастерства (ГК РФ ст. 1057–1061). Исход зависит от навыка, не от ГСЧ. Приз начисляется на депозит клуба и не выводится на карту или в наличные.';

    public function __construct(
        private readonly KitchenOrderPrintService $kitchen,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function modes(): array
    {
        return [
            [
                'id' => 'cs2:1v1_aim',
                'game' => 'cs2',
                'mode' => '1v1_aim',
                'label' => 'CS2 1v1 AIM',
                'team_size' => 1,
            ],
            [
                'id' => 'cs2:2v2_wingman',
                'game' => 'cs2',
                'mode' => '2v2_wingman',
                'label' => 'CS2 2v2 Wingman',
                'team_size' => 2,
            ],
            [
                'id' => 'dota:1v1_mid',
                'game' => 'dota',
                'mode' => '1v1_mid',
                'label' => 'Dota 2 1v1 Mid Only',
                'team_size' => 1,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function liveFor(?Computer $computer, ?Booking $booking, ?User $user): array
    {
        $clubId = $computer?->club_id ? (int) $computer->club_id : 0;
        if ($clubId < 1 && $user) {
            $clubId = (int) (app(ClubFeatureService::class)->clubIdForUser($user) ?? 0);
        }
        $enabled = $clubId > 0 && app(ClubFeatureService::class)->enabled($clubId, 'arena_duels');
        if (! $enabled) {
            return $this->emptyPayload($clubId);
        }

        $this->expirePending($clubId);
        if ($computer && $booking && $user) {
            $this->watchDisconnects($clubId, $computer, $user);
        }

        $open = ArenaDuel::query()
            ->with(['participants.user', 'participants.computer', 'creator', 'creatorComputer', 'targetComputer'])
            ->where('club_id', $clubId)
            ->where('status', ArenaDuel::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->limit(24)
            ->get();

        $live = ArenaDuel::query()
            ->with(['participants.user', 'participants.computer', 'creator', 'creatorComputer'])
            ->where('club_id', $clubId)
            ->whereIn('status', [
                ArenaDuel::STATUS_ACCEPTED,
                ArenaDuel::STATUS_IN_PROGRESS,
                ArenaDuel::STATUS_PAUSED,
            ])
            ->orderByDesc('id')
            ->limit(12)
            ->get();

        $recent = ArenaDuel::query()
            ->with(['participants.user', 'creatorComputer', 'winner'])
            ->where('club_id', $clubId)
            ->whereIn('status', [ArenaDuel::STATUS_COMPLETED, ArenaDuel::STATUS_FORFEIT])
            ->where('completed_at', '>=', now()->subMinutes(8))
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        $incoming = null;
        $mine = null;
        if ($computer && $user) {
            foreach ($open as $duel) {
                if ($this->isIncomingFor($duel, $computer, $user)) {
                    $incoming = $this->payload($duel, $computer, $booking);
                    break;
                }
            }
            $mineRow = $this->activeForUser((int) $user->id, $clubId);
            if ($mineRow) {
                $mine = $this->payload($mineRow, $computer, $booking);
            }
        }

        $settings = $this->settings($clubId);

        return [
            'enabled' => true,
            'legal' => $this->legalBlock(),
            'modes' => $this->modes(),
            'presets' => self::PRESETS,
            'min_entry_fee' => $settings['min_entry_fee'],
            'max_entry_fee' => $settings['max_entry_fee'],
            'rake_percent' => $settings['rake_percent'],
            'incoming' => $incoming,
            'mine' => $mine,
            'open' => $open->map(fn (ArenaDuel $d) => $this->payload($d, $computer, $booking))->values()->all(),
            'live' => $live->map(fn (ArenaDuel $d) => $this->payload($d, $computer, $booking))->values()->all(),
            'recent' => $recent->map(fn (ArenaDuel $d) => $this->payload($d, $computer, $booking))->values()->all(),
            'targets' => $computer && $booking ? $this->targets($computer, $booking) : [],
            'highlight_computer_ids' => $this->highlightIds($open->concat($live)),
            'whisper' => $this->maybeIncomingWhisper($incoming, $computer),
            'ticker' => $this->ticker($live->first() ?? $recent->first()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cabinetFor(User $user): array
    {
        $booking = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();
        $computer = $booking ? Computer::query()->find((int) $booking->computer_id) : null;
        $pack = $this->liveFor($computer, $booking, $user);
        $clubId = $computer?->club_id ? (int) $computer->club_id : (int) (app(ClubFeatureService::class)->clubIdForUser($user) ?? 0);
        if ($clubId > 0 && $pack['enabled']) {
            $club = Club::query()->find($clubId);
            if ($club) {
                $ids = $pack['highlight_computer_ids'] ?? [];
                $pcs = Computer::query()
                    ->where('club_id', $clubId)
                    ->where('kind', 'pc')
                    ->orderBy('name')
                    ->get(['id', 'name', 'x', 'y', 'kind']);
                $pack['map_config'] = is_array($club->map_config) ? $club->map_config : (json_decode((string) $club->map_config, true) ?: []);
                $pack['computers'] = $pcs->map(fn (Computer $c) => [
                    'id' => (int) $c->id,
                    'name' => (string) $c->name,
                    'x' => $c->x,
                    'y' => $c->y,
                    'kind' => $c->kind ?: 'pc',
                ])->values()->all();
                $pack['occupied_ids'] = Booking::query()
                    ->where('status', 'active')
                    ->whereNotNull('computer_id')
                    ->whereIn('computer_id', $pcs->pluck('id'))
                    ->pluck('computer_id')
                    ->map(fn ($id) => (string) $id)
                    ->values()
                    ->all();
                $pack['highlight_computer_ids'] = array_map('intval', $ids);
            }
        }

        return $pack;
    }

    /**
     * @return array<string, mixed>
     */
    public function tvOverlay(?Computer $computer): ?array
    {
        $clubId = $computer?->club_id ? (int) $computer->club_id : 0;
        if ($clubId < 1 || ! app(ClubFeatureService::class)->enabled($clubId, 'arena_duels')) {
            return null;
        }
        $this->expirePending($clubId);
        $row = ArenaDuel::query()
            ->with(['participants.user', 'creatorComputer', 'winner'])
            ->where('club_id', $clubId)
            ->whereIn('status', [
                ArenaDuel::STATUS_IN_PROGRESS,
                ArenaDuel::STATUS_PAUSED,
                ArenaDuel::STATUS_COMPLETED,
                ArenaDuel::STATUS_FORFEIT,
            ])
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->first();
        if (! $row) {
            return null;
        }
        if (in_array($row->status, [ArenaDuel::STATUS_COMPLETED, ArenaDuel::STATUS_FORFEIT], true)
            && $row->completed_at
            && $row->completed_at->lt(now()->subMinutes(8))) {
            return null;
        }

        return $this->ticker($row);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, Computer $from, Booking $booking, array $input): ArenaDuel
    {
        $clubId = $from->club_id ? (int) $from->club_id : 0;
        app(ClubFeatureService::class)->assertEnabled($clubId ?: null, 'arena_duels', 'Арена выключена');
        $this->expirePending($clubId);

        $parsed = $this->parseMode((string) ($input['game'] ?? 'cs2'), (string) ($input['mode'] ?? '1v1_aim'));
        $settings = $this->settings($clubId);
        $fee = round((float) ($input['entry_fee'] ?? 0), 2);
        if ($fee + 0.009 < $settings['min_entry_fee'] || $fee > $settings['max_entry_fee']) {
            throw new RuntimeException('Взнос от '.$settings['min_entry_fee'].' до '.$settings['max_entry_fee'].' ₽');
        }

        $scope = $this->normalizeScope((string) ($input['scope'] ?? 'hall'));
        $targetComputer = null;
        $targetUserId = null;
        $zoneGroup = null;
        if ($scope === ArenaDuel::SCOPE_COMPUTER) {
            $targetId = (int) ($input['target_computer_id'] ?? 0);
            if ($targetId < 1 || $targetId === (int) $from->id) {
                throw new RuntimeException('Выберите чужой ПК в клубе');
            }
            $targetComputer = Computer::query()->find($targetId);
            if (! $targetComputer || (int) $targetComputer->club_id !== (int) $from->club_id) {
                throw new RuntimeException('Соперник не в этом клубе');
            }
            $targetBooking = $this->activeOnComputer((int) $targetComputer->id);
            if (! $targetBooking) {
                throw new RuntimeException('На этом месте сейчас никто не сидит');
            }
            $targetUserId = (int) $targetBooking->user_id;
            $this->assertCooldown((int) $actor->id, (int) $targetComputer->id, $settings['cooldown_seconds']);
            $this->assertRankGap($actor, User::query()->find($targetUserId), $parsed['game'], $settings['rank_delta']);
        } elseif ($scope === ArenaDuel::SCOPE_ZONE) {
            $mine = ClanWarService::zoneGroup($from->space?->zone?->slug ?? $this->zoneSlug($from));
            if ($mine === null) {
                throw new RuntimeException('Межзонная дуэль доступна из Bootcamp или Standard');
            }
            $zoneGroup = $mine === 'bootcamp' ? 'standard' : 'bootcamp';
        }

        $mates = $this->teamSeats($booking, $from, $parsed['team_size']);
        foreach ($mates as $seat) {
            $this->assertNoActiveDuel((int) $seat['user']->id, $clubId);
        }
        $ttl = $scope === ArenaDuel::SCOPE_COMPUTER
            ? now()->addSeconds($settings['invite_seconds'])
            : now()->addMinutes($settings['open_ttl_minutes']);

        $duel = DB::transaction(function () use (
            $actor, $from, $booking, $clubId, $parsed, $fee, $scope, $targetComputer, $targetUserId, $zoneGroup, $mates, $ttl, $settings
        ) {
            foreach ($mates as $seat) {
                $this->holdEntry($seat['user'], $fee, 'arena hold');
            }
            $password = strtoupper(Str::random(6));
            $connect = $this->connectUri($parsed['game'], $password);
            $expected = $parsed['team_size'] * 2;
            $rakePct = $settings['rake_percent'];
            $total = round($fee * $expected, 2);
            $rake = round($total * $rakePct / 100, 2);
            $prize = round($total - $rake, 2);

            $duel = ArenaDuel::query()->create([
                'uuid' => (string) Str::uuid(),
                'club_id' => $clubId,
                'creator_user_id' => $actor->id,
                'creator_computer_id' => $from->id,
                'creator_booking_id' => $booking->id,
                'target_computer_id' => $targetComputer?->id,
                'target_user_id' => $targetUserId,
                'scope' => $scope,
                'zone_group' => $zoneGroup,
                'game' => $parsed['game'],
                'mode' => $parsed['mode'],
                'entry_fee' => $fee,
                'total_pot' => $total,
                'rake_percent' => $rakePct,
                'rake_amount' => $rake,
                'winner_prize' => $prize,
                'first_to' => $parsed['game'] === 'dota' ? 1 : $settings['first_to_cs'],
                'status' => ArenaDuel::STATUS_PENDING,
                'server_connect_uri' => $connect,
                'server_password' => $password,
                'expires_at' => $ttl,
                'match_data_snapshot' => ['legal' => $this->legalBlock()],
            ]);

            foreach ($mates as $seat) {
                ArenaDuelParticipant::query()->create([
                    'duel_id' => $duel->id,
                    'user_id' => $seat['user']->id,
                    'computer_id' => $seat['computer']->id,
                    'booking_id' => $seat['booking']->id,
                    'team_slot' => 1,
                    'escrow_status' => ArenaDuelParticipant::ESCROW_HELD,
                    'joined_at' => now(),
                ]);
                Transaction::create([
                    'user_id' => $seat['user']->id,
                    'amount' => -$fee,
                    'type' => 'purchase',
                    'source' => 'arena_duel',
                    'is_taxable' => false,
                    'description' => 'Арена: взнос '.$this->modeLabel($parsed['game'], $parsed['mode']),
                    'payload' => ['duel_id' => $duel->id, 'uuid' => $duel->uuid],
                ]);
            }

            return $duel;
        });

        if ($targetComputer) {
            Cache::put($this->cooldownKey((int) $actor->id, (int) $targetComputer->id), 1, $settings['cooldown_seconds']);
        }

        return $duel->fresh(['participants.user', 'participants.computer', 'creatorComputer']);
    }

    public function accept(User $actor, Computer $from, Booking $booking, ArenaDuel $duel): ArenaDuel
    {
        $clubId = $from->club_id ? (int) $from->club_id : 0;
        app(ClubFeatureService::class)->assertEnabled($clubId ?: null, 'arena_duels', 'Арена выключена');
        $this->expirePending($clubId);

        return DB::transaction(function () use ($actor, $from, $booking, $duel) {
            $duel = ArenaDuel::query()->lockForUpdate()->findOrFail($duel->id);
            if ($duel->status !== ArenaDuel::STATUS_PENDING) {
                throw new RuntimeException('Вызов уже закрыт');
            }
            if ($duel->expires_at && $duel->expires_at->isPast()) {
                throw new RuntimeException('Вызов истёк');
            }
            if ((int) $duel->creator_user_id === (int) $actor->id) {
                throw new RuntimeException('Нельзя принять свой вызов');
            }
            $this->assertCanAccept($duel, $from, $actor);
            $settings = $this->settings((int) $duel->club_id);
            $this->assertRankGap(
                User::query()->find($duel->creator_user_id),
                $actor,
                $duel->game,
                $settings['rank_delta']
            );
            $mode = $this->parseMode($duel->game, $duel->mode);
            $mates = $this->teamSeats($booking, $from, $mode['team_size']);
            foreach ($mates as $seat) {
                $this->assertNoActiveDuel((int) $seat['user']->id, (int) $duel->club_id, (int) $duel->id);
            }
            foreach ($mates as $seat) {
                $this->holdEntry($seat['user'], (float) $duel->entry_fee, 'arena accept');
                ArenaDuelParticipant::query()->create([
                    'duel_id' => $duel->id,
                    'user_id' => $seat['user']->id,
                    'computer_id' => $seat['computer']->id,
                    'booking_id' => $seat['booking']->id,
                    'team_slot' => 2,
                    'escrow_status' => ArenaDuelParticipant::ESCROW_HELD,
                    'joined_at' => now(),
                ]);
                Transaction::create([
                    'user_id' => $seat['user']->id,
                    'amount' => -(float) $duel->entry_fee,
                    'type' => 'purchase',
                    'source' => 'arena_duel',
                    'is_taxable' => false,
                    'description' => 'Арена: взнос '.$this->modeLabel($duel->game, $duel->mode),
                    'payload' => ['duel_id' => $duel->id, 'uuid' => $duel->uuid],
                ]);
            }

            $held = ArenaDuelParticipant::query()->where('duel_id', $duel->id)->count();
            $total = round((float) $duel->entry_fee * $held, 2);
            $rake = round($total * (float) $duel->rake_percent / 100, 2);
            $duel->update([
                'status' => ArenaDuel::STATUS_ACCEPTED,
                'total_pot' => $total,
                'rake_amount' => $rake,
                'winner_prize' => round($total - $rake, 2),
                'started_at' => now(),
            ]);

            return $duel->fresh(['participants.user', 'participants.computer', 'creatorComputer']);
        });
    }

    public function decline(User $actor, Computer $from, ArenaDuel $duel): ArenaDuel
    {
        return DB::transaction(function () use ($actor, $from, $duel) {
            $duel = ArenaDuel::query()->lockForUpdate()->findOrFail($duel->id);
            if ($duel->status !== ArenaDuel::STATUS_PENDING) {
                throw new RuntimeException('Вызов уже закрыт');
            }
            if ($duel->scope !== ArenaDuel::SCOPE_COMPUTER) {
                throw new RuntimeException('Открытый котёл нельзя отклонить — дождитесь истечения');
            }
            $isTarget = (int) $duel->target_computer_id === (int) $from->id
                || (int) $duel->target_user_id === (int) $actor->id;
            if (! $isTarget) {
                throw new RuntimeException('Этот вызов адресован не вам');
            }
            $this->refundHeld($duel, 'Арена: соперник отклонил вызов');
            $duel->update(['status' => ArenaDuel::STATUS_CANCELLED, 'completed_at' => now()]);

            return $duel->fresh();
        });
    }

    public function cancel(User $actor, ArenaDuel $duel): ArenaDuel
    {
        return DB::transaction(function () use ($actor, $duel) {
            $duel = ArenaDuel::query()->lockForUpdate()->findOrFail($duel->id);
            if ((int) $duel->creator_user_id !== (int) $actor->id) {
                throw new RuntimeException('Снять вызов может только автор');
            }
            if ($duel->status !== ArenaDuel::STATUS_PENDING) {
                throw new RuntimeException('После акцепта отменить может только администратор');
            }
            $this->refundHeld($duel, 'Арена: вызов снят');
            $duel->update(['status' => ArenaDuel::STATUS_CANCELLED, 'completed_at' => now()]);

            return $duel->fresh();
        });
    }

    public function forceRefund(ArenaDuel $duel, string $reason = 'Арена: возврат администратором'): ArenaDuel
    {
        return DB::transaction(function () use ($duel, $reason) {
            $duel = ArenaDuel::query()->lockForUpdate()->findOrFail($duel->id);
            if (in_array($duel->status, [ArenaDuel::STATUS_COMPLETED, ArenaDuel::STATUS_EXPIRED], true)
                && ! $this->hasHeld($duel)) {
                return $duel;
            }
            $this->refundHeld($duel, $reason);
            $duel->update([
                'status' => ArenaDuel::STATUS_CANCELLED,
                'completed_at' => now(),
            ]);

            return $duel->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $snap
     * @return array<string, mixed>|null
     */
    public function ingest(Computer $computer, User $user, Booking $booking, array $snap): ?array
    {
        $clubId = $computer->club_id ? (int) $computer->club_id : 0;
        if ($clubId < 1 || ! app(ClubFeatureService::class)->enabled($clubId, 'arena_duels')) {
            return null;
        }
        $this->expirePending($clubId);

        $event = strtolower(trim((string) ($snap['event'] ?? 'heartbeat')));
        $game = ($snap['game'] ?? '') === 'dota' ? 'dota' : 'cs2';

        $participant = ArenaDuelParticipant::query()
            ->where('computer_id', $computer->id)
            ->where('user_id', $user->id)
            ->whereHas('duel', function ($q) use ($clubId) {
                $q->where('club_id', $clubId)
                    ->whereIn('status', [
                        ArenaDuel::STATUS_ACCEPTED,
                        ArenaDuel::STATUS_IN_PROGRESS,
                        ArenaDuel::STATUS_PAUSED,
                    ]);
            })
            ->latest('id')
            ->first();
        if (! $participant) {
            return null;
        }

        $duel = $participant->duel;
        if (! $duel || $duel->game !== $game && $event !== 'heartbeat') {
            // still heartbeats for disconnect watch
        }

        $participant->update(['last_gsi_at' => now()]);

        if (in_array($duel->status, [ArenaDuel::STATUS_ACCEPTED, ArenaDuel::STATUS_PAUSED], true)
            && ! empty($snap['in_match'])) {
            $duel->update([
                'status' => ArenaDuel::STATUS_IN_PROGRESS,
                'paused_at' => null,
                'started_at' => $duel->started_at ?? now(),
            ]);
            $duel->refresh();
        }

        $this->watchDisconnects($clubId, $computer, $user);
        $duel->refresh();
        if (! $duel->isLive()) {
            return $this->settledPayload($duel->fresh(['participants.user', 'winner']));
        }

        if (! in_array($event, ['round_win', 'match_win'], true)) {
            return null;
        }
        if ($duel->game !== $game) {
            return null;
        }

        return DB::transaction(function () use ($duel, $participant, $snap, $event, $computer) {
            $duel = ArenaDuel::query()->lockForUpdate()->findOrFail($duel->id);
            if (! in_array($duel->status, [ArenaDuel::STATUS_IN_PROGRESS, ArenaDuel::STATUS_ACCEPTED], true)) {
                return $this->settledPayload($duel);
            }
            $row = ArenaDuelParticipant::query()->lockForUpdate()->findOrFail($participant->id);
            $snapshot = $duel->match_data_snapshot ?? [];
            $round = (int) ($snap['round'] ?? 0);
            $matchId = (string) ($snap['match_id'] ?? '');
            $key = $row->user_id.':'.$event.':'.$matchId.':'.$round;
            $seen = $snapshot['seen'] ?? [];
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
            $snapshot['seen'] = $seen;

            if ($event === 'round_win') {
                $row->rounds_won = (int) $row->rounds_won + 1;
                $row->save();
            }

            $freshOpp = $this->opponentGsiFresh($duel, $row);
            $wonByRounds = (int) $row->rounds_won >= (int) $duel->first_to;
            $wonByMatch = $event === 'match_win';
            if ((! $wonByRounds && ! $wonByMatch) || ! $freshOpp) {
                $duel->update(['match_data_snapshot' => $snapshot]);

                return null;
            }

            return $this->settleLocked($duel, $row, $snapshot, $computer, $wonByMatch ? 'match_win' : 'rounds');
        });
    }

    public function expirePending(?int $clubId = null): int
    {
        $q = ArenaDuel::query()
            ->where('status', ArenaDuel::STATUS_PENDING)
            ->where('expires_at', '<=', now());
        if ($clubId) {
            $q->where('club_id', $clubId);
        }
        $n = 0;
        foreach ($q->get() as $duel) {
            DB::transaction(function () use ($duel, &$n) {
                $row = ArenaDuel::query()->lockForUpdate()->find($duel->id);
                if (! $row || $row->status !== ArenaDuel::STATUS_PENDING) {
                    return;
                }
                $this->refundHeld($row, 'Арена: вызов истёк');
                $row->update(['status' => ArenaDuel::STATUS_EXPIRED, 'completed_at' => now()]);
                $n++;
            });
        }

        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(ArenaDuel $duel, ?Computer $viewer = null, ?Booking $booking = null): array
    {
        $duel->loadMissing(['participants.user', 'participants.computer', 'creator', 'creatorComputer', 'targetComputer', 'winner']);
        $seconds = 0;
        if ($duel->expires_at && $duel->status === ArenaDuel::STATUS_PENDING) {
            $seconds = max(0, $duel->expires_at->getTimestamp() - now()->getTimestamp());
        }
        $viewerId = $viewer ? (int) $viewer->id : 0;
        $viewerUserId = $booking ? (int) $booking->user_id : 0;
        $mySlot = null;
        foreach ($duel->participants as $p) {
            if ($viewerId > 0 && (int) $p->computer_id === $viewerId) {
                $mySlot = (int) $p->team_slot;
            }
        }
        $teams = [1 => [], 2 => []];
        foreach ($duel->participants as $p) {
            $teams[(int) $p->team_slot][] = [
                'user_id' => (int) $p->user_id,
                'name' => (string) ($p->user?->name ?? 'Игрок'),
                'computer_id' => (int) $p->computer_id,
                'pc' => (string) ($p->computer?->name ?? ''),
                'rounds_won' => (int) $p->rounds_won,
                'escrow' => $p->escrow_status,
            ];
        }

        $connect = trim((string) ($duel->server_connect_uri ?: ''));
        if ($connect === '' && $duel->server_password) {
            $connect = 'password '.$duel->server_password;
        }

        return [
            'id' => (int) $duel->id,
            'uuid' => (string) $duel->uuid,
            'status' => $duel->status,
            'scope' => $duel->scope,
            'game' => $duel->game,
            'mode' => $duel->mode,
            'mode_label' => $this->modeLabel($duel->game, $duel->mode),
            'entry_fee' => (float) $duel->entry_fee,
            'total_pot' => (float) $duel->total_pot,
            'rake_percent' => (float) $duel->rake_percent,
            'rake_amount' => (float) $duel->rake_amount,
            'winner_prize' => (float) $duel->winner_prize,
            'first_to' => (int) $duel->first_to,
            'creator_computer_id' => (int) $duel->creator_computer_id,
            'creator_pc' => (string) ($duel->creatorComputer?->name ?? ''),
            'creator_name' => (string) ($duel->creator?->name ?? ''),
            'target_computer_id' => $duel->target_computer_id ? (int) $duel->target_computer_id : null,
            'target_pc' => $duel->targetComputer?->name,
            'zone_group' => $duel->zone_group,
            'expires_at' => optional($duel->expires_at)?->toIso8601String(),
            'seconds_left' => $seconds,
            'started_at' => optional($duel->started_at)?->toIso8601String(),
            'completed_at' => optional($duel->completed_at)?->toIso8601String(),
            'winner_user_id' => $duel->winner_user_id ? (int) $duel->winner_user_id : null,
            'winner_name' => $duel->winner?->name,
            'teams' => $teams,
            'mine' => $mySlot !== null || ($viewerUserId > 0 && $viewerUserId === (int) $duel->creator_user_id),
            'incoming' => $this->isIncomingFor($duel, $viewer, $booking?->user),
            'can_accept' => $duel->status === ArenaDuel::STATUS_PENDING && $viewer && $viewerUserId
                && $viewerUserId !== (int) $duel->creator_user_id,
            'can_cancel' => $duel->status === ArenaDuel::STATUS_PENDING && $viewerUserId === (int) $duel->creator_user_id,
            'can_decline' => $duel->status === ArenaDuel::STATUS_PENDING
                && $duel->scope === ArenaDuel::SCOPE_COMPUTER
                && $viewer
                && ((int) $duel->target_computer_id === (int) $viewer->id || $viewerUserId === (int) $duel->target_user_id),
            'connect' => $connect,
            'password' => $duel->server_password,
            'legal' => $this->legalBlock(),
            'line' => $this->line($duel),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function legalBlock(): array
    {
        return [
            'kind' => 'skill_contest',
            'article' => 'ГК РФ ст. 1057–1061',
            'prize' => 'internal_credit',
            'cash_out' => false,
            'notice' => self::LEGAL_NOTICE,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function settings(int $clubId): array
    {
        $f = app(ClubFeatureService::class);

        return [
            'min_entry_fee' => max(10, $f->int($clubId, 'arena_duels', 'min_entry_fee', 50)),
            'max_entry_fee' => max(50, $f->int($clubId, 'arena_duels', 'max_entry_fee', 5000)),
            'rake_percent' => min(20, max(0, $f->int($clubId, 'arena_duels', 'rake_percent', 10))),
            'invite_seconds' => max(20, $f->int($clubId, 'arena_duels', 'invite_seconds', 60)),
            'open_ttl_minutes' => max(1, $f->int($clubId, 'arena_duels', 'open_ttl_minutes', 5)),
            'rank_delta' => max(0, $f->int($clubId, 'arena_duels', 'rank_delta', 3)),
            'disconnect_seconds' => max(30, $f->int($clubId, 'arena_duels', 'disconnect_seconds', 90)),
            'cooldown_seconds' => max(30, $f->int($clubId, 'arena_duels', 'cooldown_seconds', 120)),
            'first_to_cs' => max(1, $f->int($clubId, 'arena_duels', 'first_to_cs', 8)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(int $clubId): array
    {
        return [
            'enabled' => false,
            'legal' => $this->legalBlock(),
            'modes' => $this->modes(),
            'presets' => self::PRESETS,
            'incoming' => null,
            'mine' => null,
            'open' => [],
            'live' => [],
            'recent' => [],
            'targets' => [],
            'highlight_computer_ids' => [],
            'whisper' => null,
            'ticker' => null,
        ];
    }

    /**
     * @param  iterable<int, ArenaDuel>  $duels
     * @return list<int>
     */
    private function highlightIds(iterable $duels): array
    {
        $ids = [];
        foreach ($duels as $duel) {
            $ids[] = (int) $duel->creator_computer_id;
            if ($duel->target_computer_id) {
                $ids[] = (int) $duel->target_computer_id;
            }
            foreach ($duel->participants as $p) {
                $ids[] = (int) $p->computer_id;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param  array<string, mixed>|null  $incoming
     */
    private function maybeIncomingWhisper(?array $incoming, ?Computer $computer): ?string
    {
        if (! $incoming || ! $computer || empty($incoming['can_decline']) && empty($incoming['incoming'])) {
            return null;
        }
        if (empty($incoming['incoming'])) {
            return null;
        }
        $key = 'arena:whisper:'.$incoming['uuid'].':'.$computer->id;
        if (! Cache::add($key, 1, 90)) {
            return null;
        }
        $pc = $incoming['creator_pc'] ?? 'ПК';
        $fee = (int) ($incoming['entry_fee'] ?? 0);

        return 'Вам брошен вызов на дуэль с '.$pc.'. Банк '.($fee * 2).' рублей';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ticker(?ArenaDuel $duel): ?array
    {
        if (! $duel) {
            return null;
        }
        $duel->loadMissing(['participants.user', 'creatorComputer', 'winner']);

        return [
            'uuid' => $duel->uuid,
            'status' => $duel->status,
            'line' => $this->line($duel),
            'pot' => (float) $duel->total_pot,
            'mode_label' => $this->modeLabel($duel->game, $duel->mode),
        ];
    }

    private function line(ArenaDuel $duel): string
    {
        $pc = $duel->creatorComputer?->name ?: 'ПК';
        $name = $duel->winner?->name ?: $duel->creator?->name ?: 'Игрок';
        $pot = number_format((float) $duel->total_pot, 0, '.', ' ');
        if (in_array($duel->status, [ArenaDuel::STATUS_COMPLETED, ArenaDuel::STATUS_FORFEIT], true)) {
            return $name.' победил в дуэли на '.$pc.'! Банк: '.$pot.' ₽';
        }
        if ($duel->status === ArenaDuel::STATUS_PENDING) {
            return 'ВЫЗОВ НА ДУЭЛЬ: '.$pc.' ставит '.number_format((float) $duel->entry_fee, 0, '.', ' ').' ₽ ('.$this->modeLabel($duel->game, $duel->mode).')';
        }

        return 'Дуэль '.$this->modeLabel($duel->game, $duel->mode).' · банк '.$pot.' ₽';
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    private function targets(Computer $computer, Booking $booking): array
    {
        return app(LanBountyService::class)->targets($computer, $booking);
    }

    /**
     * @return array{game:string,mode:string,team_size:int}
     */
    private function parseMode(string $game, string $mode): array
    {
        $game = $game === 'dota' || $game === 'dota2' ? 'dota' : 'cs2';
        $mode = strtolower(trim($mode));
        foreach ($this->modes() as $row) {
            if ($row['game'] === $game && $row['mode'] === $mode) {
                return $row;
            }
        }
        throw new RuntimeException('Неизвестный режим дуэли');
    }

    private function modeLabel(string $game, string $mode): string
    {
        foreach ($this->modes() as $row) {
            if ($row['game'] === $game && $row['mode'] === $mode) {
                return $row['label'];
            }
        }

        return strtoupper($game).' '.$mode;
    }

    private function normalizeScope(string $scope): string
    {
        return match ($scope) {
            ArenaDuel::SCOPE_COMPUTER, 'pc' => ArenaDuel::SCOPE_COMPUTER,
            ArenaDuel::SCOPE_ZONE, 'bootcamp' => ArenaDuel::SCOPE_ZONE,
            default => ArenaDuel::SCOPE_HALL,
        };
    }

    /**
     * @return list<array{user:User,computer:Computer,booking:Booking}>
     */
    private function teamSeats(Booking $booking, Computer $computer, int $teamSize): array
    {
        $user = User::query()->find($booking->user_id);
        if (! $user) {
            throw new RuntimeException('Игрок не найден');
        }
        $seats = [[
            'user' => $user,
            'computer' => $computer,
            'booking' => $booking,
        ]];
        if ($teamSize < 2) {
            return $seats;
        }
        if (! $booking->booking_group_id) {
            throw new RuntimeException('Для 2v2 нужна пати из двух ПК');
        }
        $others = Booking::query()
            ->where('booking_group_id', $booking->booking_group_id)
            ->where('status', 'active')
            ->where('id', '!=', $booking->id)
            ->get();
        if ($others->count() !== 1) {
            throw new RuntimeException('Для 2v2 в пати должно быть ровно два активных места');
        }
        $mateBooking = $others->first();
        $matePc = Computer::query()->find((int) $mateBooking->computer_id);
        $mateUser = User::query()->find((int) $mateBooking->user_id);
        if (! $matePc || ! $mateUser) {
            throw new RuntimeException('Напарник не найден');
        }
        $seats[] = [
            'user' => $mateUser,
            'computer' => $matePc,
            'booking' => $mateBooking,
        ];

        return $seats;
    }

    private function holdEntry(User $user, float $amount, string $why): void
    {
        $locked = User::query()->lockForUpdate()->findOrFail($user->id);
        $locked->syncBalanceToWallet();
        $wallet = $locked->wallet()->lockForUpdate()->first();
        if (! $wallet) {
            throw new RuntimeException('Кошелёк не найден');
        }
        if ((float) $locked->availableBalance() + 0.009 < $amount) {
            throw new RuntimeException('Недостаточно депозита на взнос');
        }
        $wallet->debitSpendable($amount);
    }

    private function refundHeld(ArenaDuel $duel, string $description): void
    {
        $rows = ArenaDuelParticipant::query()
            ->where('duel_id', $duel->id)
            ->where('escrow_status', ArenaDuelParticipant::ESCROW_HELD)
            ->lockForUpdate()
            ->get();
        foreach ($rows as $row) {
            $this->creditUser((int) $row->user_id, (float) $duel->entry_fee, $description, [
                'duel_id' => $duel->id,
                'uuid' => $duel->uuid,
            ], 'arena_duel_refund');
            $row->update(['escrow_status' => ArenaDuelParticipant::ESCROW_REFUNDED]);
        }
    }

    private function hasHeld(ArenaDuel $duel): bool
    {
        return ArenaDuelParticipant::query()
            ->where('duel_id', $duel->id)
            ->where('escrow_status', ArenaDuelParticipant::ESCROW_HELD)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function creditUser(int $userId, float $amount, string $description, array $payload, string $source): void
    {
        if ($amount <= 0) {
            return;
        }
        $user = User::query()->lockForUpdate()->find($userId);
        if (! $user) {
            return;
        }
        $user->syncBalanceToWallet();
        $wallet = $user->wallet()->lockForUpdate()->first();
        if (! $wallet) {
            return;
        }
        $wallet->creditSpendable($amount);
        Transaction::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'type' => 'deposit',
            'source' => $source,
            'is_taxable' => false,
            'description' => $description,
            'payload' => $payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function settleLocked(
        ArenaDuel $duel,
        ArenaDuelParticipant $winnerRow,
        array $snapshot,
        Computer $winnerPc,
        string $reason,
    ): array {
        $winners = ArenaDuelParticipant::query()
            ->where('duel_id', $duel->id)
            ->where('team_slot', $winnerRow->team_slot)
            ->lockForUpdate()
            ->get();
        $losers = ArenaDuelParticipant::query()
            ->where('duel_id', $duel->id)
            ->where('team_slot', '!=', $winnerRow->team_slot)
            ->lockForUpdate()
            ->get();
        $prize = (float) $duel->winner_prize;
        $share = $winners->count() > 0 ? round($prize / $winners->count(), 2) : $prize;
        $paid = 0.0;
        foreach ($winners as $i => $row) {
            $cut = $i === $winners->count() - 1 ? round($prize - $paid, 2) : $share;
            $paid += $cut;
            if ($row->escrow_status === ArenaDuelParticipant::ESCROW_HELD) {
                $this->creditUser((int) $row->user_id, $cut, 'Арена: приз мастерства', [
                    'duel_id' => $duel->id,
                    'uuid' => $duel->uuid,
                    'rake' => (float) $duel->rake_amount,
                ], 'arena_duel_prize');
                $row->update(['escrow_status' => ArenaDuelParticipant::ESCROW_SETTLED]);
            }
        }
        foreach ($losers as $row) {
            if ($row->escrow_status === ArenaDuelParticipant::ESCROW_HELD) {
                $row->update(['escrow_status' => ArenaDuelParticipant::ESCROW_SETTLED]);
            }
        }
        $snapshot['reason'] = $reason;
        $snapshot['scores'] = ArenaDuelParticipant::query()
            ->where('duel_id', $duel->id)
            ->get()
            ->mapWithKeys(fn (ArenaDuelParticipant $p) => [(string) $p->user_id => (int) $p->rounds_won])
            ->all();
        $duel->update([
            'status' => $reason === 'forfeit' ? ArenaDuel::STATUS_FORFEIT : ArenaDuel::STATUS_COMPLETED,
            'winner_user_id' => $winnerRow->user_id,
            'completed_at' => now(),
            'paused_at' => null,
            'match_data_snapshot' => $snapshot,
        ]);
        $fresh = $duel->fresh(['participants.user', 'winner', 'creatorComputer']);
        $this->maybePrintVoucher($fresh, $winnerPc);
        $this->cueWinLight($winnerPc);

        return $this->settledPayload($fresh);
    }

    /**
     * @return array<string, mixed>
     */
    private function settledPayload(?ArenaDuel $duel): array
    {
        if (! $duel) {
            return [];
        }
        $pack = $this->payload($duel);

        return [
            'uuid' => $duel->uuid,
            'status' => $duel->status,
            'winner_user_id' => $duel->winner_user_id ? (int) $duel->winner_user_id : null,
            'winner_name' => $duel->winner?->name,
            'prize' => (float) $duel->winner_prize,
            'pot' => (float) $duel->total_pot,
            'rake' => (float) $duel->rake_amount,
            'message' => $pack['line'] ?? '',
            'play_event' => 'arena.win',
            'duel' => $pack,
        ];
    }

    private function maybePrintVoucher(?ArenaDuel $duel, Computer $pc): void
    {
        if (! $duel) {
            return;
        }
        $clubId = (int) $duel->club_id;
        if (! app(ClubFeatureService::class)->bool($clubId, 'arena_duels', 'print_voucher', false)) {
            return;
        }
        $name = $duel->winner?->name ?: 'Игрок';
        $summary = 'АРЕНА: '.$name.' · банк '.((int) $duel->total_pot).' ₽';
        try {
            $orderId = (int) DB::table('orders')->insertGetId([
                'user_id' => $duel->winner_user_id,
                'booking_id' => $duel->creator_booking_id,
                'product_name' => $summary,
                'items' => json_encode([[
                    'name' => $summary,
                    'qty' => 1,
                    'unit_price' => 0,
                    'line_total' => 0,
                ]], JSON_UNESCAPED_UNICODE),
                'price' => 0,
                'pc_name' => OrderDeliveryTarget::labelForComputerId((int) $pc->id) ?: (string) $pc->name,
                'channel' => OrderChannel::SHELL,
                'status' => Order::STATUS_PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $order = Order::query()->find($orderId);
            if ($order) {
                $this->kitchen->enqueue($order);
            }
        } catch (\Throwable) {
        }
    }

    private function cueWinLight(Computer $pc): void
    {
        try {
            app(LightControlService::class)->cueNamedEvent($pc, 'arena.win');
        } catch (\Throwable) {
        }
    }

    private function opponentGsiFresh(ArenaDuel $duel, ArenaDuelParticipant $winnerRow): bool
    {
        $settings = $this->settings((int) $duel->club_id);
        $cutoff = now()->subSeconds($settings['disconnect_seconds']);
        $opp = ArenaDuelParticipant::query()
            ->where('duel_id', $duel->id)
            ->where('team_slot', '!=', $winnerRow->team_slot)
            ->get();
        if ($opp->isEmpty()) {
            return false;
        }
        foreach ($opp as $row) {
            if (! $row->last_gsi_at || $row->last_gsi_at->lt($cutoff)) {
                return false;
            }
        }

        return true;
    }

    private function watchDisconnects(int $clubId, Computer $computer, User $user): void
    {
        $settings = $this->settings($clubId);
        $stale = now()->subSeconds($settings['disconnect_seconds']);
        $pauseAfter = now()->subSeconds((int) max(20, floor($settings['disconnect_seconds'] / 2)));
        $rows = ArenaDuel::query()
            ->where('club_id', $clubId)
            ->whereIn('status', [ArenaDuel::STATUS_IN_PROGRESS, ArenaDuel::STATUS_PAUSED, ArenaDuel::STATUS_ACCEPTED])
            ->whereHas('participants', fn ($q) => $q->where('computer_id', $computer->id))
            ->get();
        foreach ($rows as $duel) {
            $parts = $duel->participants()->get();
            if ($parts->count() < 2) {
                continue;
            }
            $mine = $parts->firstWhere('computer_id', $computer->id);
            if (! $mine) {
                continue;
            }
            $opps = $parts->where('team_slot', '!=', $mine->team_slot);
            $oppStale = $opps->every(function (ArenaDuelParticipant $p) use ($stale, $pauseAfter, $duel) {
                $gsiDead = ! $p->last_gsi_at || $p->last_gsi_at->lt($stale);
                $pc = Computer::query()->find($p->computer_id);
                $powerDead = $pc && (! $pc->last_seen_at || $pc->last_seen_at->lt($stale));

                return $gsiDead || $powerDead;
            });
            $oppPause = $opps->every(function (ArenaDuelParticipant $p) use ($pauseAfter) {
                return ! $p->last_gsi_at || $p->last_gsi_at->lt($pauseAfter);
            });
            $mineFresh = $mine->last_gsi_at && $mine->last_gsi_at->gte($stale);
            if ($oppStale && $mineFresh && $duel->status !== ArenaDuel::STATUS_PENDING) {
                DB::transaction(function () use ($duel, $mine) {
                    $locked = ArenaDuel::query()->lockForUpdate()->find($duel->id);
                    $row = ArenaDuelParticipant::query()->lockForUpdate()->find($mine->id);
                    if (! $locked || ! $row || ! in_array($locked->status, [
                        ArenaDuel::STATUS_IN_PROGRESS,
                        ArenaDuel::STATUS_PAUSED,
                        ArenaDuel::STATUS_ACCEPTED,
                    ], true)) {
                        return;
                    }
                    $losers = ArenaDuelParticipant::query()
                        ->where('duel_id', $locked->id)
                        ->where('team_slot', '!=', $row->team_slot)
                        ->get();
                    foreach ($losers as $loser) {
                        if ($loser->escrow_status === ArenaDuelParticipant::ESCROW_HELD) {
                            $loser->update(['escrow_status' => ArenaDuelParticipant::ESCROW_FORFEIT]);
                        }
                    }
                    $winnerPc = Computer::query()->find($row->computer_id);
                    if ($winnerPc) {
                        $this->settleLocked($locked, $row, $locked->match_data_snapshot ?? [], $winnerPc, 'forfeit');
                    }
                });
                continue;
            }
            if ($oppPause && $mineFresh && $duel->status === ArenaDuel::STATUS_IN_PROGRESS) {
                $duel->update(['status' => ArenaDuel::STATUS_PAUSED, 'paused_at' => now()]);
            }
        }
    }

    private function assertNoActiveDuel(int $userId, int $clubId, ?int $exceptDuelId = null): void
    {
        $q = ArenaDuelParticipant::query()
            ->where('user_id', $userId)
            ->whereHas('duel', function ($d) use ($clubId) {
                $d->where('club_id', $clubId)
                    ->whereIn('status', [
                        ArenaDuel::STATUS_PENDING,
                        ArenaDuel::STATUS_ACCEPTED,
                        ArenaDuel::STATUS_IN_PROGRESS,
                        ArenaDuel::STATUS_PAUSED,
                    ]);
            });
        if ($exceptDuelId) {
            $q->where('duel_id', '!=', $exceptDuelId);
        }
        if ($q->exists()) {
            throw new RuntimeException('Сначала завершите текущую дуэль');
        }
    }

    private function assertCanAccept(ArenaDuel $duel, Computer $from, User $actor): void
    {
        if ((int) $duel->club_id !== (int) $from->club_id) {
            throw new RuntimeException('Вызов из другого клуба');
        }
        if ($duel->scope === ArenaDuel::SCOPE_COMPUTER) {
            $ok = (int) $duel->target_computer_id === (int) $from->id
                || (int) $duel->target_user_id === (int) $actor->id;
            if (! $ok) {
                throw new RuntimeException('Этот вызов адресован другому ПК');
            }

            return;
        }
        if ($duel->scope === ArenaDuel::SCOPE_ZONE) {
            $group = ClanWarService::zoneGroup($this->zoneSlug($from));
            if ($group !== $duel->zone_group) {
                throw new RuntimeException('Межзонная дуэль — только из зоны '.$duel->zone_group);
            }
        }
    }

    private function assertCooldown(int $userId, int $targetComputerId, int $seconds): void
    {
        if (Cache::has($this->cooldownKey($userId, $targetComputerId))) {
            throw new RuntimeException('Подождите перед повторным вызовом этого ПК');
        }
    }

    private function cooldownKey(int $userId, int $targetComputerId): string
    {
        return 'arena:cd:'.$userId.':'.$targetComputerId;
    }

    private function assertRankGap(?User $a, ?User $b, string $game, int $delta): void
    {
        if (! $a || ! $b || $delta >= 8) {
            return;
        }
        $ta = $this->lastRankTier($a, $game);
        $tb = $this->lastRankTier($b, $game);
        if ($ta === null || $tb === null) {
            return;
        }
        if (abs($ta - $tb) > $delta) {
            throw new RuntimeException('Слишком большой разрыв рангов для дуэли');
        }
    }

    private function lastRankTier(User $user, string $game): ?int
    {
        $row = LanLfgQueue::query()
            ->where('user_id', $user->id)
            ->where('game', $game === 'dota' ? 'dota' : 'cs2')
            ->latest('id')
            ->first();

        return $row ? (int) $row->rank_tier : null;
    }

    private function activeOnComputer(int $computerId): ?Booking
    {
        return Booking::query()
            ->where('status', 'active')
            ->where('computer_id', $computerId)
            ->latest('id')
            ->first();
    }

    private function activeForUser(int $userId, int $clubId): ?ArenaDuel
    {
        $pid = ArenaDuelParticipant::query()
            ->where('user_id', $userId)
            ->whereHas('duel', function ($q) use ($clubId) {
                $q->where('club_id', $clubId)->whereIn('status', [
                    ArenaDuel::STATUS_PENDING,
                    ArenaDuel::STATUS_ACCEPTED,
                    ArenaDuel::STATUS_IN_PROGRESS,
                    ArenaDuel::STATUS_PAUSED,
                ]);
            })
            ->latest('id')
            ->value('duel_id');

        return $pid ? ArenaDuel::query()->with(['participants.user', 'participants.computer'])->find($pid) : null;
    }

    private function isIncomingFor(ArenaDuel $duel, ?Computer $computer, ?User $user): bool
    {
        if ($duel->status !== ArenaDuel::STATUS_PENDING || ! $computer) {
            return false;
        }
        if ($user && (int) $duel->creator_user_id === (int) $user->id) {
            return false;
        }
        if ($duel->scope !== ArenaDuel::SCOPE_COMPUTER) {
            return false;
        }

        return (int) $duel->target_computer_id === (int) $computer->id
            || ($user && (int) $duel->target_user_id === (int) $user->id);
    }

    private function zoneSlug(Computer $computer): ?string
    {
        $computer->loadMissing('space.zone');

        return $computer->space?->zone?->slug;
    }

    private function connectUri(string $game, string $password): ?string
    {
        $host = trim((string) env('ARENA_CS2_CONNECT', ''));
        if ($game === 'dota') {
            $host = trim((string) env('ARENA_DOTA_CONNECT', $host));
        }
        if ($host === '') {
            return null;
        }

        return rtrim($host, '; ').'; password '.$password;
    }

    public function findByUuid(string $uuid): ArenaDuel
    {
        $duel = ArenaDuel::query()->where('uuid', $uuid)->first();
        if (! $duel) {
            throw new RuntimeException('Дуэль не найдена');
        }

        return $duel;
    }
}
