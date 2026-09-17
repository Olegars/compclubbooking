<?php

namespace App\Services\LanLive;

use App\Models\ArenaDuel;
use App\Models\ArenaDuelParticipant;
use App\Models\ArenaKothEvening;
use App\Models\ArenaRating;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\LanLfgQueue;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ClanWarService;
use App\Services\ClubFeatureService;
use App\Services\KitchenOrderPrintService;
use App\Services\Light\LightControlService;
use App\Services\ProductStockService;
use App\Support\OrderChannel;
use App\Support\OrderDeliveryTarget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ArenaDuelService
{
    public const LEGAL_NOTICE = 'Дуэль без ставок: кто лучше на этом ПК. Рейтинг клуба, серия побед, царь горы. Перк за вечер (час или энергетик) — подарок заведения, не банк игроков.';

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
     * @return list<array<string, mixed>>
     */
    public function kinds(): array
    {
        return [
            [
                'id' => ArenaDuel::KIND_DUEL,
                'label' => 'Дуэль',
                'hint' => 'Двое. Старт, когда принят вызов.',
            ],
            [
                'id' => ArenaDuel::KIND_BATTLE,
                'label' => 'Битва',
                'hint' => 'Все желающие. Старт по набору или по времени.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function hallOfFame(int $clubId): array
    {
        $today = now()->toDateString();
        $weekStart = now()->startOfWeek()->toDateString();
        $ladder = ArenaRating::query()
            ->with('user')
            ->where('club_id', $clubId)
            ->orderByDesc('rating')
            ->limit(10)
            ->get()
            ->map(function (ArenaRating $row, int $i) {
                return [
                    'rank' => $i + 1,
                    'user_id' => (int) $row->user_id,
                    'name' => (string) ($row->user?->name ?? 'Игрок'),
                    'rating' => (int) $row->rating,
                    'wins' => (int) $row->wins,
                    'losses' => (int) $row->losses,
                    'streak' => (int) $row->streak,
                    'title' => $i === 0 ? 'Босс клуба' : null,
                ];
            })
            ->values()
            ->all();
        $streaks = ArenaRating::query()
            ->with('user')
            ->where('club_id', $clubId)
            ->where('week_start', $weekStart)
            ->orderByDesc('week_wins')
            ->orderByDesc('best_streak')
            ->limit(5)
            ->get()
            ->map(fn (ArenaRating $row) => [
                'user_id' => (int) $row->user_id,
                'name' => (string) ($row->user?->name ?? 'Игрок'),
                'week_wins' => (int) $row->week_wins,
                'best_streak' => (int) $row->best_streak,
            ])
            ->values()
            ->all();
        $koth = ArenaKothEvening::query()
            ->with('user')
            ->where('club_id', $clubId)
            ->where('recorded_on', $today)
            ->first();
        $hot = ArenaRating::query()
            ->with('user')
            ->where('club_id', $clubId)
            ->where('evening_date', $today)
            ->orderByDesc('evening_streak')
            ->first();

        $boss = $ladder[0] ?? null;
        if ($boss) {
            $seat = Booking::query()
                ->where('user_id', $boss['user_id'])
                ->where('status', 'active')
                ->latest('id')
                ->first();
            $pc = $seat ? Computer::query()->find((int) $seat->computer_id) : null;
            $boss['computer_id'] = $pc?->id;
            $boss['pc'] = $pc?->name;
            $boss['in_club'] = (bool) $seat;
        }

        return [
            'ladder' => $ladder,
            'week' => $streaks,
            'boss' => $boss,
            'koth' => $hot && (int) $hot->evening_streak > 0 ? [
                'user_id' => (int) $hot->user_id,
                'name' => (string) ($hot->user?->name ?? 'Игрок'),
                'streak' => (int) $hot->evening_streak,
                'perk' => $koth?->perk_label,
            ] : ($koth && $koth->user ? [
                'user_id' => (int) $koth->user_id,
                'name' => (string) ($koth->user->name ?? 'Игрок'),
                'streak' => (int) $koth->streak,
                'perk' => $koth->perk_label,
            ] : null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function liveFor(?Computer $computer, ?Booking $booking, ?User $user): array
    {
        $clubId = $this->resolveClubId($computer, $user);
        $enabled = $clubId > 0 && app(ClubFeatureService::class)->enabled($clubId, 'arena_duels');
        if (! $enabled) {
            return $this->emptyPayload($clubId);
        }

        $this->expirePending($clubId);
        $this->promoteReadyLobbies($clubId);
        if ($computer && $booking && $user) {
            $this->bindSeat($computer, $booking, $user);
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
        if ($user) {
            foreach ($open as $duel) {
                if ($this->isIncomingFor($duel, $computer, $user)) {
                    $incoming = $this->payload($duel, $computer, $booking, $user);
                    break;
                }
            }
            $mineRow = $this->activeForUser((int) $user->id, $clubId);
            if ($mineRow) {
                $mine = $this->payload($mineRow, $computer, $booking, $user);
            }
        }

        $settings = $this->settings($clubId);
        $board = $open->filter(fn (ArenaDuel $d) => $d->scope === ArenaDuel::SCOPE_HALL);

        $fame = $this->hallOfFame($clubId);

        return [
            'enabled' => true,
            'legal' => $this->legalBlock(),
            'modes' => $this->modes(),
            'kinds' => $this->kinds(),
            'min_battle_players' => $settings['min_battle_players'],
            'max_battle_players' => $settings['max_battle_players'],
            'advance_ttl_hours' => $settings['advance_ttl_hours'],
            'koth_min_streak' => $settings['koth_min_streak'],
            'incoming' => $incoming,
            'mine' => $mine,
            'board' => $board->map(fn (ArenaDuel $d) => $this->payload($d, $computer, $booking, $user))->values()->all(),
            'open' => $open->map(fn (ArenaDuel $d) => $this->payload($d, $computer, $booking, $user))->values()->all(),
            'live' => $live->map(fn (ArenaDuel $d) => $this->payload($d, $computer, $booking, $user))->values()->all(),
            'recent' => $recent->map(fn (ArenaDuel $d) => $this->payload($d, $computer, $booking, $user))->values()->all(),
            'ladder' => $fame['ladder'],
            'week' => $fame['week'],
            'boss' => $fame['boss'],
            'koth' => $fame['koth'],
            'me' => $user ? $this->viewerRating($clubId, (int) $user->id, $fame['boss'] ?? null) : null,
            'targets' => $computer && $booking ? $this->targets($computer, $booking) : [],
            'highlight_computer_ids' => $this->highlightIds($open->concat($live)),
            'whisper' => $this->maybeIncomingWhisper($incoming, $computer),
            'ticker' => $this->ticker($live->first() ?? $recent->first() ?? null, $fame['koth'], $fame['boss']),
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
        $clubId = $this->resolveClubId($computer, $user);
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
        $fame = $this->hallOfFame($clubId);
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
        if ($row
            && in_array($row->status, [ArenaDuel::STATUS_COMPLETED, ArenaDuel::STATUS_FORFEIT], true)
            && $row->completed_at
            && $row->completed_at->lt(now()->subMinutes(8))) {
            $row = null;
        }

        return $this->ticker($row, $fame['koth'], $fame['boss']);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, ?Computer $from, ?Booking $booking, array $input): ArenaDuel
    {
        $clubId = $this->resolveClubId($from, $actor);
        if ($clubId < 1) {
            throw new RuntimeException('Клуб не найден');
        }
        app(ClubFeatureService::class)->assertEnabled($clubId, 'arena_duels', 'Арена выключена');
        $this->expirePending($clubId);

        $kind = $this->normalizeKind((string) ($input['kind'] ?? ArenaDuel::KIND_DUEL));
        $parsed = $this->parseMode((string) ($input['game'] ?? 'cs2'), (string) ($input['mode'] ?? '1v1_aim'));
        if ($kind === ArenaDuel::KIND_BATTLE) {
            $parsed['team_size'] = 1;
        }
        $settings = $this->settings($clubId);

        $caps = $this->playerCaps($kind, $parsed['team_size'], $settings, $input);
        $scheduled = $this->parseSchedule($input['scheduled_at'] ?? null, $settings);
        $advance = $from === null || $scheduled !== null;

        $scope = $this->normalizeScope((string) ($input['scope'] ?? 'hall'));
        if ($from === null && $scope !== ArenaDuel::SCOPE_HALL && $scope !== ArenaDuel::SCOPE_COMPUTER) {
            $scope = ArenaDuel::SCOPE_HALL;
        }
        $targetComputer = null;
        $targetUserId = null;
        $zoneGroup = null;
        if ($scope === ArenaDuel::SCOPE_COMPUTER) {
            $targetId = (int) ($input['target_computer_id'] ?? 0);
            if ($targetId < 1 || ($from && $targetId === (int) $from->id)) {
                throw new RuntimeException('Выберите чужой ПК в клубе');
            }
            $targetComputer = Computer::query()->find($targetId);
            if (! $targetComputer || (int) $targetComputer->club_id !== $clubId) {
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
            if (! $from) {
                throw new RuntimeException('Межзонная дуэль доступна только с ПК');
            }
            $mine = ClanWarService::zoneGroup($from->space?->zone?->slug ?? $this->zoneSlug($from));
            if ($mine === null) {
                throw new RuntimeException('Межзонная дуэль доступна из Bootcamp или Standard');
            }
            $zoneGroup = $mine === 'bootcamp' ? 'standard' : 'bootcamp';
        }

        $mates = $this->joinParty($actor, $from, $booking, $parsed['team_size']);
        foreach ($mates as $seat) {
            $this->assertNoActiveDuel((int) $seat['user']->id, $clubId);
        }
        $ttl = $this->lobbyExpiresAt($scope, $scheduled, $advance, $settings);

        $duel = DB::transaction(function () use (
            $actor, $from, $booking, $clubId, $parsed, $scope, $targetComputer, $targetUserId,
            $zoneGroup, $mates, $ttl, $settings, $kind, $caps, $scheduled
        ) {
            $password = strtoupper(Str::random(6));
            $connect = $this->connectUri($parsed['game'], $password);

            $duel = ArenaDuel::query()->create([
                'uuid' => (string) Str::uuid(),
                'club_id' => $clubId,
                'creator_user_id' => $actor->id,
                'creator_computer_id' => $from?->id,
                'creator_booking_id' => $booking?->id,
                'target_computer_id' => $targetComputer?->id,
                'target_user_id' => $targetUserId,
                'scope' => $scope,
                'zone_group' => $zoneGroup,
                'game' => $parsed['game'],
                'mode' => $parsed['mode'],
                'kind' => $kind,
                'min_players' => $caps['min'],
                'max_players' => $caps['max'],
                'entry_fee' => 0,
                'total_pot' => 0,
                'rake_percent' => 0,
                'rake_amount' => 0,
                'winner_prize' => 0,
                'first_to' => $parsed['game'] === 'dota' ? 1 : $settings['first_to_cs'],
                'status' => ArenaDuel::STATUS_PENDING,
                'server_connect_uri' => $connect,
                'server_password' => $password,
                'expires_at' => $ttl,
                'scheduled_at' => $scheduled,
                'match_data_snapshot' => ['legal' => $this->legalBlock()],
            ]);

            foreach ($mates as $seat) {
                ArenaDuelParticipant::query()->create([
                    'duel_id' => $duel->id,
                    'user_id' => $seat['user']->id,
                    'computer_id' => $seat['computer']?->id,
                    'booking_id' => $seat['booking']?->id,
                    'team_slot' => 1,
                    'escrow_status' => ArenaDuelParticipant::ESCROW_SETTLED,
                    'held_amount' => 0,
                    'joined_at' => now(),
                ]);
            }

            return $duel;
        });

        if ($targetComputer) {
            Cache::put($this->cooldownKey((int) $actor->id, (int) $targetComputer->id), 1, $settings['cooldown_seconds']);
        }

        return $duel->fresh(['participants.user', 'participants.computer', 'creatorComputer']);
    }

    public function accept(User $actor, ?Computer $from, ?Booking $booking, ArenaDuel $duel): ArenaDuel
    {
        $clubId = $this->resolveClubId($from, $actor);
        if ($clubId < 1) {
            throw new RuntimeException('Клуб не найден');
        }
        app(ClubFeatureService::class)->assertEnabled($clubId, 'arena_duels', 'Арена выключена');
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
            if (ArenaDuelParticipant::query()->where('duel_id', $duel->id)->where('user_id', $actor->id)->exists()) {
                throw new RuntimeException('Вы уже в этом лобби');
            }
            $held = ArenaDuelParticipant::query()->where('duel_id', $duel->id)->count();
            if ($held >= (int) $duel->max_players) {
                throw new RuntimeException('Лобби уже набрано');
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
            $teamSize = ($duel->kind === ArenaDuel::KIND_BATTLE) ? 1 : $mode['team_size'];
            $mates = $this->joinParty($actor, $from, $booking, $teamSize);
            if ($held + count($mates) > (int) $duel->max_players) {
                throw new RuntimeException('Не хватает мест в лобби');
            }
            foreach ($mates as $seat) {
                $this->assertNoActiveDuel((int) $seat['user']->id, (int) $duel->club_id, (int) $duel->id);
            }
            $nextSlot = $this->nextTeamSlot($duel, $teamSize);
            foreach ($mates as $seat) {
                ArenaDuelParticipant::query()->create([
                    'duel_id' => $duel->id,
                    'user_id' => $seat['user']->id,
                    'computer_id' => $seat['computer']?->id,
                    'booking_id' => $seat['booking']?->id,
                    'team_slot' => $nextSlot,
                    'escrow_status' => ArenaDuelParticipant::ESCROW_SETTLED,
                    'held_amount' => 0,
                    'joined_at' => now(),
                ]);
                if ($teamSize < 2) {
                    $nextSlot++;
                }
            }

            $this->syncLobbyLocked($duel);

            return $duel->fresh(['participants.user', 'participants.computer', 'creatorComputer']);
        });
    }

    public function decline(User $actor, ?Computer $from, ArenaDuel $duel): ArenaDuel
    {
        return DB::transaction(function () use ($actor, $from, $duel) {
            $duel = ArenaDuel::query()->lockForUpdate()->findOrFail($duel->id);
            if ($duel->status !== ArenaDuel::STATUS_PENDING) {
                throw new RuntimeException('Вызов уже закрыт');
            }
            if ($duel->scope !== ArenaDuel::SCOPE_COMPUTER) {
                throw new RuntimeException('Открытый котёл нельзя отклонить — дождитесь истечения');
            }
            $isTarget = ($from && (int) $duel->target_computer_id === (int) $from->id)
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

    public function start(User $actor, ArenaDuel $duel): ArenaDuel
    {
        return DB::transaction(function () use ($actor, $duel) {
            $duel = ArenaDuel::query()->lockForUpdate()->findOrFail($duel->id);
            if ((int) $duel->creator_user_id !== (int) $actor->id) {
                throw new RuntimeException('Старт может дать только автор');
            }
            if ($duel->status !== ArenaDuel::STATUS_PENDING) {
                throw new RuntimeException('Лобби уже закрыто');
            }
            $held = ArenaDuelParticipant::query()->where('duel_id', $duel->id)->count();
            if ($held < (int) $duel->min_players) {
                throw new RuntimeException('Нужно минимум '.$duel->min_players.' игроков');
            }
            $this->syncLobbyLocked($duel, true);

            return $duel->fresh(['participants.user', 'participants.computer', 'creatorComputer']);
        });
    }

    public function proposeRaise(User $actor, ArenaDuel $duel, float $newFee): ArenaDuel
    {
        throw new RuntimeException('Ставки на арене отключены');
    }

    public function voteRaise(User $actor, ArenaDuel $duel, bool $agree): ArenaDuel
    {
        throw new RuntimeException('Ставки на арене отключены');
    }

    public function forceRefund(ArenaDuel $duel, string $reason = 'Арена: снято администратором'): ArenaDuel
    {
        return DB::transaction(function () use ($duel) {
            $duel = ArenaDuel::query()->lockForUpdate()->findOrFail($duel->id);
            if (in_array($duel->status, [ArenaDuel::STATUS_COMPLETED, ArenaDuel::STATUS_EXPIRED, ArenaDuel::STATUS_CANCELLED], true)) {
                return $duel;
            }
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
            ->where('user_id', $user->id)
            ->where(function ($q) use ($computer) {
                $q->where('computer_id', $computer->id)->orWhereNull('computer_id');
            })
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
        if (! $participant->computer_id) {
            $participant->update([
                'computer_id' => $computer->id,
                'booking_id' => $booking->id,
            ]);
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
                $held = ArenaDuelParticipant::query()->where('duel_id', $row->id)->count();
                if ($held >= (int) $row->min_players) {
                    $this->syncLobbyLocked($row, true);
                } else {
                    $row->update(['status' => ArenaDuel::STATUS_EXPIRED, 'completed_at' => now()]);
                }
                $n++;
            });
        }

        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(ArenaDuel $duel, ?Computer $viewer = null, ?Booking $booking = null, ?User $user = null): array
    {
        $duel->loadMissing(['participants.user', 'participants.computer', 'creator', 'creatorComputer', 'targetComputer', 'winner']);
        $seconds = 0;
        if ($duel->expires_at && $duel->status === ArenaDuel::STATUS_PENDING) {
            $seconds = max(0, $duel->expires_at->getTimestamp() - now()->getTimestamp());
        }
        $viewerId = $viewer ? (int) $viewer->id : 0;
        $viewerUserId = $user ? (int) $user->id : ($booking ? (int) $booking->user_id : 0);
        $mySlot = null;
        $joined = false;
        foreach ($duel->participants as $p) {
            if ($viewerUserId > 0 && (int) $p->user_id === $viewerUserId) {
                $mySlot = (int) $p->team_slot;
                $joined = true;
            } elseif ($viewerId > 0 && (int) $p->computer_id === $viewerId) {
                $mySlot = (int) $p->team_slot;
                $joined = true;
            }
        }
        $teams = [];
        $players = [];
        foreach ($duel->participants as $p) {
            $snap = $this->ratingSnapshot((int) $duel->club_id, (int) $p->user_id);
            $row = [
                'user_id' => (int) $p->user_id,
                'name' => (string) ($p->user?->name ?? 'Игрок'),
                'computer_id' => $p->computer_id ? (int) $p->computer_id : null,
                'pc' => (string) ($p->computer?->name ?? ''),
                'rounds_won' => (int) $p->rounds_won,
                'rating' => $snap['rating'],
                'streak' => $snap['streak'],
            ];
            $teams[(int) $p->team_slot][] = $row;
            $players[] = $row;
        }
        $held = count($players);

        $connect = trim((string) ($duel->server_connect_uri ?: ''));
        if ($connect === '' && $duel->server_password) {
            $connect = 'password '.$duel->server_password;
        }
        $full = $held >= (int) $duel->max_players;
        $lobbyOpen = $duel->status === ArenaDuel::STATUS_PENDING && ! $full;

        return [
            'id' => (int) $duel->id,
            'uuid' => (string) $duel->uuid,
            'status' => $duel->status,
            'scope' => $duel->scope,
            'kind' => (string) ($duel->kind ?: ArenaDuel::KIND_DUEL),
            'kind_label' => $this->kindLabel((string) ($duel->kind ?: ArenaDuel::KIND_DUEL)),
            'game' => $duel->game,
            'mode' => $duel->mode,
            'mode_label' => $this->modeLabel($duel->game, $duel->mode),
            'first_to' => (int) $duel->first_to,
            'min_players' => (int) ($duel->min_players ?: 2),
            'max_players' => (int) ($duel->max_players ?: 2),
            'players_count' => $held,
            'players' => $players,
            'scheduled_at' => optional($duel->scheduled_at)?->toIso8601String(),
            'creator_computer_id' => $duel->creator_computer_id ? (int) $duel->creator_computer_id : null,
            'creator_pc' => (string) ($duel->creatorComputer?->name ?? ''),
            'creator_name' => (string) ($duel->creator?->name ?? ''),
            'creator_rating' => $this->ratingSnapshot((int) $duel->club_id, (int) $duel->creator_user_id)['rating'],
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
            'joined' => $joined,
            'mine' => $joined || ($viewerUserId > 0 && $viewerUserId === (int) $duel->creator_user_id),
            'incoming' => $this->isIncomingFor($duel, $viewer, $user ?? $booking?->user),
            'can_accept' => $lobbyOpen && $viewerUserId > 0 && ! $joined
                && $viewerUserId !== (int) $duel->creator_user_id
                && ($duel->scope === ArenaDuel::SCOPE_HALL
                    || $this->isIncomingFor($duel, $viewer, $user ?? $booking?->user)
                    || ($duel->scope === ArenaDuel::SCOPE_ZONE && $viewer)),
            'can_cancel' => $duel->status === ArenaDuel::STATUS_PENDING && $viewerUserId === (int) $duel->creator_user_id,
            'can_start' => $duel->status === ArenaDuel::STATUS_PENDING
                && $viewerUserId === (int) $duel->creator_user_id
                && $held >= (int) ($duel->min_players ?: 2)
                && $held < (int) ($duel->max_players ?: 2),
            'can_decline' => $duel->status === ArenaDuel::STATUS_PENDING
                && $duel->scope === ArenaDuel::SCOPE_COMPUTER
                && $this->isIncomingFor($duel, $viewer, $user ?? $booking?->user),
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
            'kind' => 'club_ladder',
            'cash_out' => false,
            'notice' => self::LEGAL_NOTICE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(int $clubId): array
    {
        $f = app(ClubFeatureService::class);

        return [
            'invite_seconds' => max(20, $f->int($clubId, 'arena_duels', 'invite_seconds', 60)),
            'open_ttl_minutes' => max(1, $f->int($clubId, 'arena_duels', 'open_ttl_minutes', 5)),
            'advance_ttl_hours' => max(1, min(48, $f->int($clubId, 'arena_duels', 'advance_ttl_hours', 24))),
            'min_battle_players' => max(3, min(8, $f->int($clubId, 'arena_duels', 'min_battle_players', 3))),
            'max_battle_players' => max(3, min(16, $f->int($clubId, 'arena_duels', 'max_battle_players', 8))),
            'koth_min_streak' => max(1, min(10, $f->int($clubId, 'arena_duels', 'koth_min_streak', 3))),
            'koth_minutes' => max(15, min(180, $f->int($clubId, 'arena_duels', 'koth_minutes', 60))),
            'koth_prefer_drink' => $f->bool($clubId, 'arena_duels', 'koth_prefer_drink', true),
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
            'kinds' => $this->kinds(),
            'incoming' => null,
            'mine' => null,
            'board' => [],
            'open' => [],
            'live' => [],
            'recent' => [],
            'ladder' => [],
            'week' => [],
            'boss' => null,
            'koth' => null,
            'me' => null,
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

        return 'Вам брошен вызов на дуэль с '.$pc.'. 1 на 1 или зассал.';
    }

    /**
     * @param  array<string, mixed>|null  $koth
     * @param  array<string, mixed>|null  $boss
     * @return array<string, mixed>|null
     */
    private function ticker(?ArenaDuel $duel, ?array $koth = null, ?array $boss = null): ?array
    {
        if ($koth && ! empty($koth['name'])) {
            return [
                'uuid' => 'koth',
                'status' => 'koth',
                'line' => 'ЦАРЬ ГОРЫ: '.$koth['name'].' · серия '.$koth['streak'],
                'mode_label' => 'KOTH',
            ];
        }
        if ($duel) {
            $duel->loadMissing(['participants.user', 'creatorComputer', 'winner']);

            return [
                'uuid' => $duel->uuid,
                'status' => $duel->status,
                'line' => $this->line($duel),
                'mode_label' => $this->modeLabel($duel->game, $duel->mode),
            ];
        }
        if ($boss && ! empty($boss['name'])) {
            return [
                'uuid' => 'boss',
                'status' => 'ladder',
                'line' => 'БОСС КЛУБА: '.$boss['name'].' · Elo '.$boss['rating'],
                'mode_label' => 'Ladder',
            ];
        }

        return null;
    }

    private function line(ArenaDuel $duel): string
    {
        $kind = $this->kindLabel((string) ($duel->kind ?: ArenaDuel::KIND_DUEL));
        $pc = $duel->creatorComputer?->name ?: ($duel->creator?->name ?: 'Игрок');
        $name = $duel->winner?->name ?: $duel->creator?->name ?: 'Игрок';
        $when = $duel->scheduled_at ? $duel->scheduled_at->timezone(config('app.timezone'))->format('d.m H:i') : '';
        if (in_array($duel->status, [ArenaDuel::STATUS_COMPLETED, ArenaDuel::STATUS_FORFEIT], true)) {
            return $name.' закрыл '.mb_strtolower($kind).' на '.$pc;
        }
        if ($duel->status === ArenaDuel::STATUS_PENDING) {
            $tail = $when !== '' ? ' · '.$when : '';
            $count = $duel->participants->count();
            $cap = (int) ($duel->max_players ?: 2);

            return $kind.': '.$pc.' · '.$this->modeLabel($duel->game, $duel->mode).' · '.$count.'/'.$cap.$tail;
        }

        return $kind.' '.$this->modeLabel($duel->game, $duel->mode);
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

    private function refundHeld(ArenaDuel $duel, string $description): void
    {
        // Ставки сняты: вызов просто закрывается.
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
        foreach ($winners as $row) {
            $row->update(['escrow_status' => ArenaDuelParticipant::ESCROW_SETTLED]);
        }
        foreach ($losers as $row) {
            $row->update(['escrow_status' => ArenaDuelParticipant::ESCROW_SETTLED]);
        }
        $ladder = $this->applyRatingsLocked($duel, $winners, $losers, $winnerPc);
        $snapshot['reason'] = $reason;
        $snapshot['ladder'] = $ladder;
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
        $pack = $this->settledPayload($fresh);
        $pack['perk'] = $ladder['perk'] ?? null;
        $pack['koth'] = $ladder['koth'] ?? null;

        return $pack;
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
            'prize' => 0,
            'pot' => 0,
            'rake' => 0,
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
        $summary = 'АРЕНА: '.$name.' — босс зала';
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

    private function assertCanAccept(ArenaDuel $duel, ?Computer $from, User $actor): void
    {
        $clubId = $this->resolveClubId($from, $actor);
        if ((int) $duel->club_id !== $clubId) {
            throw new RuntimeException('Вызов из другого клуба');
        }
        if ($duel->scope === ArenaDuel::SCOPE_COMPUTER) {
            $ok = (int) $duel->target_user_id === (int) $actor->id
                || ($from && (int) $duel->target_computer_id === (int) $from->id);
            if (! $ok) {
                throw new RuntimeException('Этот вызов адресован другому ПК');
            }

            return;
        }
        if ($duel->scope === ArenaDuel::SCOPE_ZONE) {
            if (! $from) {
                throw new RuntimeException('Межзонная дуэль — только с ПК');
            }
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
        if ($duel->status !== ArenaDuel::STATUS_PENDING) {
            return false;
        }
        if ($user && (int) $duel->creator_user_id === (int) $user->id) {
            return false;
        }
        if ($duel->scope !== ArenaDuel::SCOPE_COMPUTER) {
            return false;
        }
        if ($user && (int) $duel->target_user_id === (int) $user->id) {
            return true;
        }

        return $computer && (int) $duel->target_computer_id === (int) $computer->id;
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

    private function resolveClubId(?Computer $from, ?User $user): int
    {
        if ($from && $from->club_id) {
            return (int) $from->club_id;
        }
        $fromUser = (int) (app(ClubFeatureService::class)->clubIdForUser($user) ?? 0);
        if ($fromUser > 0) {
            return $fromUser;
        }

        return (int) (Club::query()->orderBy('id')->value('id') ?? 0);
    }

    private function normalizeKind(string $kind): string
    {
        return $kind === ArenaDuel::KIND_BATTLE ? ArenaDuel::KIND_BATTLE : ArenaDuel::KIND_DUEL;
    }

    private function kindLabel(string $kind): string
    {
        return $kind === ArenaDuel::KIND_BATTLE ? 'Битва' : 'Дуэль';
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $input
     * @return array{min:int,max:int}
     */
    private function playerCaps(string $kind, int $teamSize, array $settings, array $input): array
    {
        if ($kind !== ArenaDuel::KIND_BATTLE) {
            $n = max(2, $teamSize * 2);

            return ['min' => $n, 'max' => $n];
        }
        $max = (int) ($input['max_players'] ?? $settings['max_battle_players']);
        $max = max($settings['min_battle_players'], min($settings['max_battle_players'], $max));
        $min = min($settings['min_battle_players'], $max);

        return ['min' => $min, 'max' => $max];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function parseSchedule(mixed $raw, array $settings): ?\Carbon\CarbonInterface
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $at = \Carbon\CarbonImmutable::parse((string) $raw);
        } catch (\Throwable) {
            throw new RuntimeException('Некорректное время старта');
        }
        if ($at->lte(now())) {
            throw new RuntimeException('Время старта уже прошло');
        }
        $horizon = now()->addHours($settings['advance_ttl_hours']);
        if ($at->gt($horizon)) {
            throw new RuntimeException('Можно назначить не дальше чем на '.$settings['advance_ttl_hours'].' ч');
        }

        return $at;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function lobbyExpiresAt(string $scope, ?\Carbon\CarbonInterface $scheduled, bool $advance, array $settings): \Carbon\CarbonInterface
    {
        if ($scheduled) {
            return $scheduled->addMinutes($settings['open_ttl_minutes']);
        }
        if ($advance) {
            return now()->addHours($settings['advance_ttl_hours']);
        }
        if ($scope === ArenaDuel::SCOPE_COMPUTER) {
            return now()->addSeconds($settings['invite_seconds']);
        }

        return now()->addMinutes($settings['open_ttl_minutes']);
    }

    private function syncLobbyLocked(ArenaDuel $duel, bool $force = false): void
    {
        $held = ArenaDuelParticipant::query()->where('duel_id', $duel->id)->count();
        $minMet = $held >= (int) $duel->min_players;
        $full = $held >= (int) $duel->max_players;
        $promote = $force || $full || (($duel->kind ?: ArenaDuel::KIND_DUEL) === ArenaDuel::KIND_DUEL && $minMet);
        $duel->update([
            'status' => $promote ? ArenaDuel::STATUS_ACCEPTED : ArenaDuel::STATUS_PENDING,
            'started_at' => $promote ? ($duel->started_at ?? now()) : $duel->started_at,
        ]);
    }

    /**
     * @return list<array{user:User,computer:?Computer,booking:?Booking}>
     */
    private function joinParty(User $actor, ?Computer $computer, ?Booking $booking, int $teamSize): array
    {
        if ($teamSize < 2) {
            return [[
                'user' => $actor,
                'computer' => $computer,
                'booking' => $booking,
            ]];
        }
        if (! $booking || ! $computer) {
            throw new RuntimeException('Для 2v2 нужна активная пати из двух ПК');
        }

        return $this->teamSeats($booking, $computer, $teamSize);
    }

    private function nextTeamSlot(ArenaDuel $duel, int $teamSize): int
    {
        if ($teamSize >= 2) {
            return 2;
        }
        $max = (int) ArenaDuelParticipant::query()->where('duel_id', $duel->id)->max('team_slot');

        return max(1, $max) + 1;
    }

    private function promoteReadyLobbies(int $clubId): void
    {
        $rows = ArenaDuel::query()
            ->where('club_id', $clubId)
            ->where('status', ArenaDuel::STATUS_PENDING)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();
        foreach ($rows as $duel) {
            DB::transaction(function () use ($duel) {
                $row = ArenaDuel::query()->lockForUpdate()->find($duel->id);
                if (! $row || $row->status !== ArenaDuel::STATUS_PENDING) {
                    return;
                }
                $held = ArenaDuelParticipant::query()->where('duel_id', $row->id)->count();
                if ($held >= (int) $row->min_players) {
                    $this->syncLobbyLocked($row, true);
                }
            });
        }
    }

    private function bindSeat(Computer $computer, Booking $booking, User $user): void
    {
        $row = ArenaDuelParticipant::query()
            ->where('user_id', $user->id)
            ->whereNull('computer_id')
            ->whereHas('duel', function ($q) use ($computer) {
                $q->where('club_id', $computer->club_id)
                    ->whereIn('status', [
                        ArenaDuel::STATUS_PENDING,
                        ArenaDuel::STATUS_ACCEPTED,
                        ArenaDuel::STATUS_IN_PROGRESS,
                        ArenaDuel::STATUS_PAUSED,
                    ]);
            })
            ->latest('id')
            ->first();
        if (! $row) {
            return;
        }
        $row->update([
            'computer_id' => $computer->id,
            'booking_id' => $booking->id,
        ]);
        $duel = $row->duel;
        if ($duel && ! $duel->creator_computer_id && (int) $duel->creator_user_id === (int) $user->id) {
            $duel->update([
                'creator_computer_id' => $computer->id,
                'creator_booking_id' => $booking->id,
            ]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ArenaDuelParticipant>  $winners
     * @param  \Illuminate\Support\Collection<int, ArenaDuelParticipant>  $losers
     * @return array<string, mixed>
     */
    private function applyRatingsLocked(
        ArenaDuel $duel,
        $winners,
        $losers,
        Computer $winnerPc,
    ): array {
        $clubId = (int) $duel->club_id;
        $today = now()->toDateString();
        $weekStart = now()->startOfWeek()->toDateString();
        $winnerIds = $winners->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $loserIds = $losers->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $avgLoser = 0;
        $nLosers = 0;
        foreach ($loserIds as $id) {
            $avgLoser += $this->ratingRow($clubId, $id)->rating;
            $nLosers++;
        }
        $avgLoser = $nLosers > 0 ? (int) round($avgLoser / $nLosers) : ArenaRating::BASE;
        $perk = null;
        $koth = null;
        foreach ($winnerIds as $id) {
            $row = $this->ratingRow($clubId, $id);
            $swing = 20 + min(20, intdiv(abs((int) $row->rating - $avgLoser), 25));
            $weekWins = ($row->week_start && $row->week_start->toDateString() === $weekStart) ? (int) $row->week_wins + 1 : 1;
            $eveningStreak = ($row->evening_date && $row->evening_date->toDateString() === $today) ? (int) $row->evening_streak + 1 : 1;
            $streak = (int) $row->streak + 1;
            $row->update([
                'rating' => (int) $row->rating + $swing,
                'wins' => (int) $row->wins + 1,
                'streak' => $streak,
                'best_streak' => max((int) $row->best_streak, $streak),
                'week_wins' => $weekWins,
                'week_start' => $weekStart,
                'evening_date' => $today,
                'evening_streak' => $eveningStreak,
            ]);
            $granted = $this->maybeGrantKothPerk($clubId, $id, $eveningStreak, $winnerPc);
            if ($granted) {
                $perk = $granted;
            }
            $koth = [
                'user_id' => $id,
                'streak' => $eveningStreak,
                'name' => (string) (User::query()->find($id)?->name ?? 'Игрок'),
            ];
        }
        foreach ($loserIds as $id) {
            $row = $this->ratingRow($clubId, $id);
            $swing = 20 + min(20, intdiv(abs((int) $row->rating - $avgLoser), 25));
            $drop = (int) round($swing * 0.6);
            $row->update([
                'rating' => max(100, (int) $row->rating - $drop),
                'losses' => (int) $row->losses + 1,
                'streak' => 0,
                'evening_date' => $today,
                'evening_streak' => 0,
            ]);
        }

        return ['perk' => $perk, 'koth' => $koth];
    }

    /**
     * @param  array<string, mixed>|null  $boss
     * @return array<string, mixed>
     */
    private function viewerRating(int $clubId, int $userId, ?array $boss): array
    {
        $snap = $this->ratingSnapshot($clubId, $userId);
        if ($boss && (int) ($boss['user_id'] ?? 0) === $userId) {
            $snap['title'] = 'Босс клуба';
        }

        return $snap;
    }

    /**
     * @return array{rating:int,wins:int,losses:int,streak:int,evening_streak:int,week_wins:int,title:?string}
     */
    private function ratingSnapshot(int $clubId, int $userId): array
    {
        $row = ArenaRating::query()->where('club_id', $clubId)->where('user_id', $userId)->first();
        $today = now()->toDateString();
        $weekStart = now()->startOfWeek()->toDateString();

        return [
            'rating' => (int) ($row?->rating ?? ArenaRating::BASE),
            'wins' => (int) ($row?->wins ?? 0),
            'losses' => (int) ($row?->losses ?? 0),
            'streak' => (int) ($row?->streak ?? 0),
            'evening_streak' => ($row && $row->evening_date && $row->evening_date->toDateString() === $today) ? (int) $row->evening_streak : 0,
            'week_wins' => ($row && $row->week_start && $row->week_start->toDateString() === $weekStart) ? (int) $row->week_wins : 0,
            'title' => null,
        ];
    }

    private function ratingRow(int $clubId, int $userId): ArenaRating
    {
        return ArenaRating::query()->firstOrCreate(
            ['club_id' => $clubId, 'user_id' => $userId],
            ['rating' => ArenaRating::BASE]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function maybeGrantKothPerk(int $clubId, int $userId, int $eveningStreak, Computer $pc): ?array
    {
        $settings = $this->settings($clubId);
        if ($eveningStreak < $settings['koth_min_streak']) {
            return null;
        }
        $today = now()->toDateString();
        $evening = ArenaKothEvening::query()->firstOrCreate(
            ['club_id' => $clubId, 'recorded_on' => $today],
            ['user_id' => $userId, 'streak' => $eveningStreak]
        );
        if ($eveningStreak > (int) $evening->streak) {
            $evening->user_id = $userId;
            $evening->streak = $eveningStreak;
        }
        if ($evening->perk_awarded_at) {
            $evening->save();

            return null;
        }
        $user = User::query()->find($userId);
        if (! $user) {
            $evening->save();

            return null;
        }
        $perk = null;
        if ($settings['koth_prefer_drink']) {
            $perk = $this->grantKothDrink($user, $pc);
        }
        if (! $perk) {
            $perk = $this->grantKothMinutes($user, $pc, $settings['koth_minutes']);
        }
        if (! $perk && ! $settings['koth_prefer_drink']) {
            $perk = $this->grantKothDrink($user, $pc);
        }
        if ($perk) {
            $evening->perk_kind = (string) ($perk['type'] ?? '');
            $evening->perk_label = (string) ($perk['label'] ?? '');
            $evening->perk_awarded_at = now();
        }
        $evening->save();

        return $perk;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function grantKothDrink(User $user, Computer $pc): ?array
    {
        $product = Product::query()
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->where(function ($q) {
                $q->whereNull('requires_marking')->orWhere('requires_marking', false);
            })
            ->where(function ($q) {
                $q->where('category', 'like', '%напит%')
                    ->orWhere('category', 'like', '%бар%')
                    ->orWhere('name', 'like', '%энерг%')
                    ->orWhere('name', 'like', '%red bull%')
                    ->orWhere('name', 'like', '%адреналин%');
            })
            ->orderBy('price')
            ->orderBy('id')
            ->first();
        if (! $product) {
            return null;
        }
        $booking = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();
        try {
            $stock = app(ProductStockService::class);
            $stock->assertAvailable($product, 1);
            $stock->decrementUnmarked($product, 1, null);
        } catch (\Throwable) {
            return null;
        }
        $name = (string) $product->name;
        $orderId = (int) DB::table('orders')->insertGetId([
            'user_id' => $user->id,
            'booking_id' => $booking?->id,
            'product_name' => 'ЦАРЬ ГОРЫ: '.$name,
            'items' => json_encode([[
                'product_id' => $product->id,
                'name' => $name,
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
            try {
                $this->kitchen->enqueue($order);
            } catch (\Throwable) {
            }
        }

        return [
            'type' => 'drink',
            'label' => 'Энергетик за счёт клуба: '.$name,
            'order_id' => $orderId,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function grantKothMinutes(User $user, Computer $pc, int $minutes): ?array
    {
        $booking = Booking::query()
            ->where('user_id', $user->id)
            ->where('computer_id', $pc->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();
        if (! $booking || ! $booking->ends_at) {
            return null;
        }
        $tz = config('app.timezone');
        $ends = \Carbon\CarbonImmutable::parse($booking->ends_at, $tz);
        $newEnds = $ends->addMinutes($minutes);
        $conflict = Booking::query()
            ->where('computer_id', $pc->id)
            ->where('status', 'active')
            ->where('id', '!=', $booking->id)
            ->where('starts_at', '<', $newEnds)
            ->where('ends_at', '>', $ends)
            ->exists();
        if ($conflict) {
            return null;
        }
        $start = $booking->actual_started_at
            ? \Carbon\CarbonImmutable::parse($booking->actual_started_at, $tz)
            : $ends;
        Booking::withoutEvents(function () use ($booking, $newEnds, $start) {
            $secs = max(1, (int) $start->diffInSeconds($newEnds));
            $booking->update([
                'ends_at' => $newEnds,
                'duration' => $secs / 3600,
            ]);
        });
        Transaction::create([
            'user_id' => $user->id,
            'amount' => 0,
            'type' => 'deposit',
            'source' => 'arena_koth',
            'is_taxable' => false,
            'description' => 'Арена: царь горы +'.$minutes.' мин за счёт клуба',
            'payload' => ['booking_id' => $booking->id, 'minutes' => $minutes],
        ]);

        return [
            'type' => 'minutes',
            'label' => '+'.$minutes.' мин за счёт клуба',
            'minutes' => $minutes,
        ];
    }
}
