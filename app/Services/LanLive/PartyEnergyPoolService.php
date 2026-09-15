<?php

namespace App\Services\LanLive;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Computer;
use App\Models\PartyEnergyPool;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BookingSessionTimingService;
use App\Services\GameBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PartyEnergyPoolService
{
    public const SIPHON_MINUTES = 10;

    public const TRIGGER_SECONDS = 90;

    public function __construct(
        private readonly ShellGsiStore $gsi,
        private readonly BookingSessionTimingService $timing,
        private readonly GameBookingService $bookings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(?Booking $booking, ?User $viewer = null): array
    {
        $empty = [
            'available' => false,
            'count' => 0,
            'minutes' => 0,
            'auto_fuel' => false,
            'is_captain' => false,
            'captain_name' => null,
        ];
        if (! $booking?->booking_group_id) {
            return $empty;
        }
        $partyCount = Booking::query()
            ->where('booking_group_id', $booking->booking_group_id)
            ->whereIn('status', ['confirmed', 'paid', 'active'])
            ->count();
        if ($partyCount < 2) {
            return $empty;
        }

        $pool = $this->poolFor($booking, create: false);
        $group = BookingGroup::query()->with('user')->find($booking->booking_group_id);
        $captainId = $pool?->captain_user_id ?: (int) ($group?->user_id ?? 0);

        return [
            'available' => true,
            'count' => $partyCount,
            'minutes' => (int) ($pool?->minutes_remaining ?? 0),
            'auto_fuel' => (bool) ($pool?->auto_fuel ?? false),
            'is_captain' => $viewer && $captainId > 0 && (int) $viewer->id === $captainId,
            'captain_name' => $group?->user?->name,
        ];
    }

    public function setAutoFuel(Booking $booking, User $actor, bool $on): PartyEnergyPool
    {
        $pool = $this->poolFor($booking, create: true);
        if ((int) $actor->id !== (int) $pool->captain_user_id) {
            throw new RuntimeException('Котёл включает только капитан пати');
        }
        $pool->update(['auto_fuel' => $on]);

        return $pool->fresh();
    }

    /**
     * @param  'deposit'|'time'  $source
     */
    public function contribute(Booking $booking, User $actor, int $minutes, string $source = 'deposit'): PartyEnergyPool
    {
        $minutes = max(5, min(60, $minutes));
        $pool = $this->poolFor($booking, create: true);
        $this->assertPartyMember($booking, $actor);

        return DB::transaction(function () use ($booking, $actor, $minutes, $source, $pool) {
            $pool = PartyEnergyPool::query()->lockForUpdate()->findOrFail($pool->id);
            if ($source === 'time') {
                $this->donateFromSession($booking, $minutes);
            } else {
                $this->buyMinutes($booking, $actor, $minutes);
            }
            $pool->increment('minutes_remaining', $minutes);

            return $pool->fresh();
        });
    }

    /**
     * Before the clock hits zero: if in-match and captain allowed auto-fuel, pull minutes.
     */
    public function maybeSiphon(Booking $booking, bool $requireMatch = true): bool
    {
        if (! $booking->booking_group_id || $booking->status !== 'active') {
            return false;
        }
        $remaining = $this->timing->remainingSeconds($booking);
        if ($remaining > self::TRIGGER_SECONDS && $remaining > 0) {
            return false;
        }
        if ($requireMatch && ! $this->gsi->inMatch((int) $booking->computer_id)) {
            return false;
        }
        $pool = $this->poolFor($booking, create: false);
        if (! $pool || ! $pool->auto_fuel || $pool->minutes_remaining < 1) {
            return false;
        }

        $take = min(self::SIPHON_MINUTES, (int) $pool->minutes_remaining);

        return $this->applySiphon($booking, $pool, $take);
    }

    /**
     * Last-chance siphon when the session is already at/past ends_at.
     */
    public function trySiphonExpired(Booking $booking): bool
    {
        if (! $booking->booking_group_id || $booking->status !== 'active') {
            return false;
        }
        if (! $this->gsi->inMatch((int) $booking->computer_id)) {
            return false;
        }
        $pool = $this->poolFor($booking, create: false);
        if (! $pool || ! $pool->auto_fuel || $pool->minutes_remaining < 1) {
            return false;
        }
        $take = min(self::SIPHON_MINUTES, (int) $pool->minutes_remaining);

        return $this->applySiphon($booking, $pool, $take);
    }

    public function poolFor(Booking $booking, bool $create): ?PartyEnergyPool
    {
        if (! $booking->booking_group_id) {
            return null;
        }
        $existing = PartyEnergyPool::query()
            ->where('booking_group_id', $booking->booking_group_id)
            ->first();
        if ($existing || ! $create) {
            return $existing;
        }
        $group = BookingGroup::query()->find($booking->booking_group_id);
        if (! $group) {
            return null;
        }
        $pc = Computer::query()->find($booking->computer_id);

        return PartyEnergyPool::query()->create([
            'booking_group_id' => $group->id,
            'club_id' => (int) ($group->club_id ?: $pc?->club_id ?: 0),
            'captain_user_id' => (int) $group->user_id,
            'minutes_remaining' => 0,
            'auto_fuel' => false,
        ]);
    }

    private function applySiphon(Booking $booking, PartyEnergyPool $pool, int $minutes): bool
    {
        $minutes = max(1, $minutes);

        return DB::transaction(function () use ($booking, $pool, $minutes) {
            $booking = Booking::query()->lockForUpdate()->find($booking->id);
            $pool = PartyEnergyPool::query()->lockForUpdate()->find($pool->id);
            if (! $booking || ! $pool || $booking->status !== 'active' || $pool->minutes_remaining < 1) {
                return false;
            }
            $take = min($minutes, (int) $pool->minutes_remaining);
            $tz = config('app.timezone');
            $now = CarbonImmutable::now($tz);
            $endsAt = $booking->ends_at
                ? CarbonImmutable::parse($booking->ends_at)->timezone($tz)
                : $now;
            if ($endsAt->lessThan($now)) {
                $endsAt = $now;
            }
            $newEnds = $endsAt->addMinutes($take);
            $pc = Computer::query()->find((int) $booking->computer_id);
            if ($pc) {
                $busy = $this->bookings->occupiedComputerIds(
                    [(int) $pc->id],
                    $endsAt,
                    $newEnds,
                    [(int) $booking->id]
                );
                if ($busy !== []) {
                    return false;
                }
            }

            $start = $booking->actual_started_at
                ? CarbonImmutable::parse($booking->actual_started_at, $tz)
                : ($booking->starts_at
                    ? CarbonImmutable::parse($booking->starts_at, $tz)
                    : $now);
            $secs = max(1, (int) $start->diffInSeconds($newEnds));
            $localStart = $start->timezone($tz);

            Booking::withoutEvents(function () use ($booking, $newEnds, $secs, $localStart) {
                $booking->update([
                    'ends_at' => $newEnds,
                    'duration' => $secs / 3600,
                    'date' => $localStart->toDateString(),
                    'start_time' => $localStart->hour
                        + ($localStart->minute / 60)
                        + ($localStart->second / 3600),
                ]);
            });

            $pool->decrement('minutes_remaining', $take);
            $pool->update(['last_siphon_at' => now()]);

            return true;
        });
    }

    private function donateFromSession(Booking $booking, int $minutes): void
    {
        $left = $this->timing->remainingSeconds($booking);
        if ($left < ($minutes * 60) + 120) {
            throw new RuntimeException('Нельзя скинуть столько — останется меньше двух минут');
        }
        $tz = config('app.timezone');
        $ends = CarbonImmutable::parse($booking->ends_at)->timezone($tz)->subMinutes($minutes);
        $start = $booking->actual_started_at
            ? CarbonImmutable::parse($booking->actual_started_at, $tz)
            : CarbonImmutable::parse($booking->starts_at, $tz);
        $secs = max(1, (int) $start->diffInSeconds($ends));
        Booking::withoutEvents(function () use ($booking, $ends, $secs) {
            $booking->update([
                'ends_at' => $ends,
                'duration' => $secs / 3600,
            ]);
        });
    }

    private function buyMinutes(Booking $booking, User $actor, int $minutes): void
    {
        $pc = Computer::query()->with('space.zone')->find((int) $booking->computer_id);
        if (! $pc) {
            throw new RuntimeException('ПК не найден');
        }
        $hourly = app(\App\Services\BookingSessionExtendService::class);
        $pack = $hourly->options($booking);
        $rate = (float) ($pack['hourly_rate'] ?? 0);
        $cost = (float) (int) round(max(0, $rate) * ($minutes / 60));
        if ($cost < 1) {
            $cost = 1.0;
        }
        $user = User::query()->lockForUpdate()->findOrFail($actor->id);
        $user->syncBalanceToWallet();
        if ((float) $user->availableBalance() + 0.009 < $cost) {
            throw new RuntimeException('Не хватает депозита на минуты в котёл');
        }
        $wallet = $user->wallet()->lockForUpdate()->first();
        if (! $wallet) {
            throw new RuntimeException('Кошелёк не найден');
        }
        $wallet->debitSpendable($cost);
        Transaction::create([
            'user_id' => $user->id,
            'amount' => -$cost,
            'type' => 'purchase',
            'source' => 'party_energy',
            'description' => sprintf('Котёл пати +%d мин', $minutes),
            'payload' => [
                'booking_id' => $booking->id,
                'minutes' => $minutes,
            ],
        ]);
    }

    private function assertPartyMember(Booking $booking, User $actor): void
    {
        $ok = Booking::query()
            ->where('booking_group_id', $booking->booking_group_id)
            ->where('user_id', $actor->id)
            ->whereIn('status', ['confirmed', 'paid', 'active'])
            ->exists();
        if (! $ok && (int) $booking->user_id !== (int) $actor->id) {
            throw new RuntimeException('Вы не в этой пати');
        }
    }
}
