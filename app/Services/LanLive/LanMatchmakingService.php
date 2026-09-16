<?php

namespace App\Services\LanLive;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Computer;
use App\Models\LanLfgQueue;
use App\Models\User;
use App\Services\BookingSeatTransferService;
use Carbon\CarbonImmutable;
use RuntimeException;

class LanMatchmakingService
{
    public const TTL_MINUTES = 20;

    public function __construct(
        private readonly BookingSeatTransferService $transfers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Booking $booking, User $user): array
    {
        $computer = Computer::query()->find((int) $booking->computer_id);
        if ($computer && ! app(\App\Services\ClubFeatureService::class)->enabled(
            $computer->club_id ? (int) $computer->club_id : null,
            'lfg'
        )) {
            return ['looking' => false, 'queue' => null];
        }
        $this->expireStale();
        $row = $this->activeFor($user, $booking);

        return [
            'looking' => $row && $row->status === LanLfgQueue::STATUS_OPEN,
            'queue' => $row ? $this->serialize($row, $booking) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function enqueue(User $user, Computer $computer, Booking $booking, string $game, string $rank): array
    {
        $clubId = $computer->club_id ? (int) $computer->club_id : null;
        app(\App\Services\ClubFeatureService::class)->assertEnabled($clubId, 'lfg', 'Поиск пати выключен');
        $this->expireStale();
        $game = $this->normalizeGame($game);
        $rank = $this->normalizeRank($rank);
        $tier = $this->rankTier($game, $rank);

        $existing = $this->activeFor($user, $booking);
        if ($existing && in_array($existing->status, [
            LanLfgQueue::STATUS_MATCHED,
            LanLfgQueue::STATUS_SEATED,
        ], true)) {
            return $this->serialize($existing, $booking);
        }

        $row = $existing && $existing->status === LanLfgQueue::STATUS_OPEN
            ? $existing
            : new LanLfgQueue;

        $row->fill([
            'club_id' => (int) ($computer->club_id ?? 0),
            'user_id' => $user->id,
            'booking_id' => $booking->id,
            'computer_id' => $computer->id,
            'game' => $game,
            'rank' => $rank,
            'rank_tier' => $tier,
            'status' => LanLfgQueue::STATUS_OPEN,
            'matched_user_id' => null,
            'matched_computer_id' => null,
            'matched_queue_id' => null,
            'matched_at' => null,
            'expires_at' => now()->addMinutes($this->ttlMinutes((int) ($computer->club_id ?? 0))),
        ]);
        $row->save();

        $mate = $this->findMate($row, $booking);
        if ($mate) {
            $this->pair($row, $mate);
            $this->bindEnergyPool($row, $mate);
            $row->refresh();
            $auto = $this->maybeAutoSit($user, $booking, $row);
            $payload = $this->serialize($row->fresh(), $booking->fresh());
            if ($auto) {
                return array_merge($payload, $auto);
            }

            return $payload;
        }

        return $this->serialize($row, $booking);
    }

    public function cancel(User $user, Booking $booking): void
    {
        $row = $this->activeFor($user, $booking);
        if (! $row) {
            return;
        }
        if ($row->status === LanLfgQueue::STATUS_OPEN) {
            $row->update(['status' => LanLfgQueue::STATUS_CANCELLED]);
        }
        if ($row->status === LanLfgQueue::STATUS_MATCHED && $row->matched_queue_id) {
            $other = LanLfgQueue::query()->find($row->matched_queue_id);
            $row->update(['status' => LanLfgQueue::STATUS_CANCELLED]);
            if ($other && $other->status === LanLfgQueue::STATUS_MATCHED) {
                $other->update([
                    'status' => LanLfgQueue::STATUS_OPEN,
                    'matched_user_id' => null,
                    'matched_computer_id' => null,
                    'matched_queue_id' => null,
                    'matched_at' => null,
                    'expires_at' => now()->addMinutes($this->ttlMinutes((int) ($row->club_id ?? 0))),
                ]);
            }
        }
    }

    /**
     * Move the current player onto a free seat next to the matched teammate.
     *
     * @return array<string, mixed>
     */
    public function sitTogether(User $user, Booking $booking): array
    {
        $row = $this->activeFor($user, $booking);
        if (! $row || $row->status !== LanLfgQueue::STATUS_MATCHED) {
            throw new RuntimeException('Сначала найдите тиммейта');
        }
        $matePc = Computer::query()->find((int) $row->matched_computer_id);
        if (! $matePc) {
            throw new RuntimeException('Тиммейт уже ушёл');
        }

        $seat = $this->bestAdjacentSeat($matePc, $booking);
        if (! $seat) {
            throw new RuntimeException(
                'Свободных мест рядом с '.$matePc->name.' нет. Дойдите пешком или включите войс в игре.'
            );
        }

        $result = $this->transfers->transfer($booking, (int) $seat['id'], $user);
        $row->update([
            'status' => LanLfgQueue::STATUS_SEATED,
            'computer_id' => (int) $seat['id'],
        ]);
        if ($row->matched_queue_id) {
            LanLfgQueue::query()->where('id', $row->matched_queue_id)->update([
                'matched_computer_id' => (int) $seat['id'],
            ]);
        }

        $discord = $this->voiceUrl();
        $voice = $discord !== ''
            ? ' Войс: Discord клуба или голосовой чат в игре.'
            : ' Войс — в игре.';

        return [
            'moved' => true,
            'auto_sat' => false,
            'to' => $result['to'] ?? $seat['name'],
            'pin_code' => $result['pin_code'] ?? null,
            'mate_pc' => (string) $matePc->name,
            'message' => 'Пересадка на '.$seat['name'].' рядом с '.$matePc->name
                .'. Войдите PIN на новом ПК.'.$voice,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(LanLfgQueue $row, ?Booking $booking = null): array
    {
        $matePc = $row->matched_computer_id
            ? Computer::query()->find((int) $row->matched_computer_id)
            : null;
        $mate = $row->matched_user_id
            ? User::query()->find((int) $row->matched_user_id)
            : null;
        $adjacent = null;
        if ($matePc && $booking && $row->status === LanLfgQueue::STATUS_MATCHED) {
            $adjacent = $this->bestAdjacentSeat($matePc, $booking);
        }

        $line = null;
        $hint = null;
        $discord = $this->voiceUrl();
        if ($row->status === LanLfgQueue::STATUS_MATCHED && $matePc) {
            $nick = trim((string) ($mate?->name ?? 'игрок')) ?: 'игрок';
            $line = 'Твой тиммейт на '.$matePc->name.' ('.$nick.', '.$row->rank.')';
            $mine = $booking?->computer_id ? Computer::query()->find((int) $booking->computer_id) : null;
            $alreadyNear = $mine && $this->alreadyAdjacent($mine, $matePc);
            if ($alreadyNear) {
                $hint = $discord !== ''
                    ? 'Вы уже рядом. Объедините войс в игре или Discord клуба.'
                    : 'Вы уже рядом. Объедините войс в игре.';
            } elseif ($adjacent) {
                $hint = 'Свободно рядом: '.$adjacent['name'].'. Пересаживаем автоматически, либо войс в игре'
                    .($discord !== '' ? ' / Discord клуба' : '').'.';
            } else {
                $hint = $discord !== ''
                    ? 'Мест рядом нет — дойдите до '.$matePc->name.' или объедините войс в игре / Discord.'
                    : 'Мест рядом нет — дойдите до '.$matePc->name.' или объедините войс в игре.';
            }
        } elseif ($row->status === LanLfgQueue::STATUS_OPEN) {
            $line = 'Ищем пати: '.$this->gameLabel($row->game).' · '.$row->rank;
            $hint = 'Покажите экран соседу или ждите матч в зале.';
        } elseif ($row->status === LanLfgQueue::STATUS_SEATED && $matePc) {
            $line = 'Тиммейт на '.$matePc->name;
            $hint = $discord !== ''
                ? 'Включите войс в игре или Discord клуба.'
                : 'Включите голосовой чат в игре.';
        }

        return [
            'id' => (int) $row->id,
            'status' => $row->status,
            'game' => $row->game,
            'rank' => $row->rank,
            'mate_pc' => $matePc?->name,
            'mate_name' => $mate?->name,
            'adjacent_computer_id' => $adjacent['id'] ?? null,
            'adjacent_pc' => $adjacent['name'] ?? null,
            'can_sit' => (bool) $adjacent,
            'voice_url' => $discord !== '' ? $discord : null,
            'line' => $line,
            'hint' => $hint,
        ];
    }

    private function activeFor(User $user, Booking $booking): ?LanLfgQueue
    {
        return LanLfgQueue::query()
            ->where('user_id', $user->id)
            ->where('booking_id', $booking->id)
            ->whereIn('status', [
                LanLfgQueue::STATUS_OPEN,
                LanLfgQueue::STATUS_MATCHED,
                LanLfgQueue::STATUS_SEATED,
            ])
            ->latest('id')
            ->first();
    }

    private function findMate(LanLfgQueue $row, Booking $booking): ?LanLfgQueue
    {
        $candidates = LanLfgQueue::query()
            ->where('club_id', $row->club_id)
            ->where('game', $row->game)
            ->where('status', LanLfgQueue::STATUS_OPEN)
            ->where('id', '!=', $row->id)
            ->where('user_id', '!=', $row->user_id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('id')
            ->get();

        $myGroup = (int) ($booking->booking_group_id ?? 0);
        $delta = max(0, app(\App\Services\ClubFeatureService::class)->int(
            (int) ($row->club_id ?? 0),
            'lfg',
            'rank_delta',
            1
        ));
        foreach ($candidates as $cand) {
            if (abs((int) $cand->rank_tier - (int) $row->rank_tier) > $delta) {
                continue;
            }
            if ($myGroup > 0) {
                $otherBooking = Booking::query()->find($cand->booking_id);
                if ($otherBooking && (int) $otherBooking->booking_group_id === $myGroup) {
                    continue;
                }
            }

            return $cand;
        }

        return null;
    }

    private function pair(LanLfgQueue $a, LanLfgQueue $b): void
    {
        $now = now();
        $a->update([
            'status' => LanLfgQueue::STATUS_MATCHED,
            'matched_user_id' => $b->user_id,
            'matched_computer_id' => $b->computer_id,
            'matched_queue_id' => $b->id,
            'matched_at' => $now,
        ]);
        $b->update([
            'status' => LanLfgQueue::STATUS_MATCHED,
            'matched_user_id' => $a->user_id,
            'matched_computer_id' => $a->computer_id,
            'matched_queue_id' => $a->id,
            'matched_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function maybeAutoSit(User $user, Booking $booking, LanLfgQueue $row): ?array
    {
        $matePc = Computer::query()->find((int) $row->matched_computer_id);
        $mine = Computer::query()->find((int) $booking->computer_id);
        if (! $matePc) {
            return null;
        }
        if ($mine && $this->alreadyAdjacent($mine, $matePc)) {
            return null;
        }
        if (! $this->bestAdjacentSeat($matePc, $booking)) {
            return null;
        }
        try {
            $moved = $this->sitTogether($user, $booking);
            $moved['auto_sat'] = true;

            return $moved;
        } catch (\Throwable) {
            return null;
        }
    }

    private function alreadyAdjacent(Computer $a, Computer $b): bool
    {
        $score = $this->proximityScore($a, $b);

        return $score !== null && $score <= 20;
    }

    private function bindEnergyPool(LanLfgQueue $a, LanLfgQueue $b): void
    {
        $ba = Booking::query()->find($a->booking_id);
        $bb = Booking::query()->find($b->booking_id);
        if (! $ba || ! $bb) {
            return;
        }
        $ga = (int) ($ba->booking_group_id ?? 0);
        $gb = (int) ($bb->booking_group_id ?? 0);
        if ($ga > 0 && $gb > 0) {
            return;
        }
        if ($ga > 0) {
            $bb->update(['booking_group_id' => $ga]);

            return;
        }
        if ($gb > 0) {
            $ba->update(['booking_group_id' => $gb]);

            return;
        }

        $start = $ba->starts_at && $bb->starts_at
            ? (CarbonImmutable::parse($ba->starts_at)->lessThan(CarbonImmutable::parse($bb->starts_at))
                ? $ba->starts_at : $bb->starts_at)
            : ($ba->starts_at ?: $bb->starts_at);
        $end = $ba->ends_at && $bb->ends_at
            ? (CarbonImmutable::parse($ba->ends_at)->greaterThan(CarbonImmutable::parse($bb->ends_at))
                ? $ba->ends_at : $bb->ends_at)
            : ($ba->ends_at ?: $bb->ends_at);

        $group = BookingGroup::query()->create([
            'user_id' => $b->user_id,
            'club_id' => (int) ($a->club_id ?: 0),
            'starts_at' => $start,
            'ends_at' => $end,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 0,
            'games_total_minor' => 0,
            'total_minor' => 0,
            'paid_total_minor' => 0,
            'paid_at' => now(),
            'pricing_snapshot' => ['source' => 'lfg'],
        ]);
        $ba->update(['booking_group_id' => $group->id]);
        $bb->update(['booking_group_id' => $group->id]);
    }

    private function voiceUrl(): string
    {
        return trim((string) config('club.socials.discord', ''));
    }

    /**
     * @return array{id:int,name:string}|null
     */
    private function bestAdjacentSeat(Computer $near, Booking $mover): ?array
    {
        $free = $this->transfers->freeTargets($mover);
        if ($free === []) {
            return null;
        }

        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($free as $row) {
            $pc = Computer::query()->find((int) $row['id']);
            if (! $pc || (int) $pc->id === (int) $near->id) {
                continue;
            }
            $score = $this->proximityScore($near, $pc);
            if ($score === null || $score >= $bestScore) {
                continue;
            }
            $bestScore = $score;
            $best = ['id' => (int) $pc->id, 'name' => (string) $pc->name];
        }

        return $best;
    }

    private function proximityScore(Computer $a, Computer $b): ?int
    {
        if ($this->nameNeighbors((string) $a->name, (string) $b->name)) {
            return 1;
        }
        $ax = (float) $a->x;
        $ay = (float) $a->y;
        $bx = (float) $b->x;
        $by = (float) $b->y;
        if ($ax == 0.0 && $ay == 0.0 && $bx == 0.0 && $by == 0.0) {
            return null;
        }
        $dist = (int) round(hypot($ax - $bx, $ay - $by));
        if ($dist <= 0 || $dist > 90) {
            return null;
        }

        return 10 + $dist;
    }

    private function nameNeighbors(string $a, string $b): bool
    {
        if (! preg_match('/(\d+)\s*$/', $a, $ma) || ! preg_match('/(\d+)\s*$/', $b, $mb)) {
            return false;
        }

        return abs((int) $ma[1] - (int) $mb[1]) === 1;
    }

    private function expireStale(): void
    {
        LanLfgQueue::query()
            ->where('status', LanLfgQueue::STATUS_OPEN)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', CarbonImmutable::now())
            ->update(['status' => LanLfgQueue::STATUS_CANCELLED]);
    }

    private function normalizeGame(string $game): string
    {
        $g = strtolower(trim($game));
        if (in_array($g, ['dota', 'dota2', 'dota 2'], true)) {
            return 'dota';
        }
        if (in_array($g, ['valorant', 'val'], true)) {
            return 'valorant';
        }

        return 'cs2';
    }

    private function normalizeRank(string $rank): string
    {
        $r = mb_strtolower(trim($rank));
        $r = preg_replace('/\s+/', ' ', $r) ?? $r;

        return mb_substr($r !== '' ? $r : 'any', 0, 32);
    }

    private function rankTier(string $game, string $rank): int
    {
        $r = mb_strtolower($rank);
        $map = $game === 'dota'
            ? ['herald' => 1, 'guardian' => 2, 'crusader' => 3, 'archon' => 4, 'legend' => 5, 'ancient' => 6, 'divine' => 7, 'immortal' => 8]
            : ($game === 'valorant'
                ? ['iron' => 1, 'bronze' => 2, 'silver' => 3, 'gold' => 4, 'plat' => 5, 'platinum' => 5, 'diamond' => 6, 'ascendant' => 7, 'immortal' => 8, 'radiant' => 9]
                : ['silver' => 2, 'gold' => 3, 'mg' => 4, 'mge' => 4, 'dmg' => 5, 'le' => 6, 'lem' => 6, 'supreme' => 7, 'global' => 8, 'faceit' => 7]);
        foreach ($map as $needle => $tier) {
            if (str_contains($r, $needle)) {
                return $tier;
            }
        }

        return 5;
    }

    private function gameLabel(string $game): string
    {
        return match ($game) {
            'dota' => 'Dota 2',
            'valorant' => 'Valorant',
            default => 'CS2',
        };
    }

    private function ttlMinutes(?int $clubId): int
    {
        return max(5, app(\App\Services\ClubFeatureService::class)->int($clubId, 'lfg', 'ttl_minutes', self::TTL_MINUTES));
    }
}
