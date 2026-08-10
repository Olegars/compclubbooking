<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Computer;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Продление активной сессии с баланса (Shell / TV).
 */
class BookingSessionExtendService
{
    /** @var list<int> */
    public const ALLOWED_MINUTES = [30, 60, 120, 180];

    public function __construct(
        private readonly TariffService $tariffs,
        private readonly GameBookingService $bookings,
        private readonly ComputerStatusService $statuses,
        private readonly BookingSessionTimingService $timing,
    ) {
    }

    /**
     * @return array{
     *   hourly_rate: float,
     *   balance: float,
     *   options: list<array<string,mixed>>
     * }
     */
    public function options(Booking $booking): array
    {
        $pc = Computer::query()->with('space.zone')->find((int) $booking->computer_id);
        if (! $pc) {
            throw new RuntimeException('ПК не найден');
        }

        $now = CarbonImmutable::now(config('app.timezone'));
        $rate = $this->hourlyRateForComputer($pc, (int) $pc->club_id, $now);
        $user = User::query()->findOrFail((int) $booking->user_id);
        $balance = (float) $user->availableBalance();
        $endsAt = $this->currentEndsAt($booking, $now);

        $options = [];
        foreach (self::ALLOWED_MINUTES as $minutes) {
            $cost = $this->costForMinutes($rate, $minutes);
            $newEnds = $endsAt->addMinutes($minutes);
            $conflict = false;
            try {
                $this->assertNoConflict($booking, $pc, $endsAt, $newEnds);
            } catch (RuntimeException) {
                $conflict = true;
            }
            $shortage = max(0.0, round($cost - $balance, 2));
            $options[] = [
                'minutes' => $minutes,
                'label' => $this->labelForMinutes($minutes),
                'cost' => $cost,
                'hourly_rate' => $rate,
                'can_pay' => ! $conflict && $shortage <= 0.009,
                'shortage' => $shortage,
                'suggested_topup' => $this->suggestedTopUp($shortage),
                'conflict' => $conflict,
                'ends_at_after' => $newEnds->toIso8601String(),
            ];
        }

        return [
            'hourly_rate' => $rate,
            'balance' => $balance,
            'ends_at' => $endsAt->toIso8601String(),
            'time_remaining' => $this->timing->formatRemainingHms($booking),
            'options' => $options,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(Booking $booking, int $minutes): array
    {
        $this->assertAllowedMinutes($minutes);
        $pack = $this->options($booking);
        $option = collect($pack['options'])->firstWhere('minutes', $minutes);
        if (! is_array($option)) {
            throw new RuntimeException('Недоступный интервал');
        }

        return array_merge($pack, [
            'minutes' => $minutes,
            'cost' => $option['cost'],
            'can_pay' => $option['can_pay'],
            'shortage' => $option['shortage'],
            'suggested_topup' => $option['suggested_topup'],
            'conflict' => $option['conflict'],
            'ends_at_after' => $option['ends_at_after'],
            'balance_after' => round($pack['balance'] - (float) $option['cost'], 2),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function extend(Booking $booking, User $actor, int $minutes): array
    {
        $this->assertAllowedMinutes($minutes);

        return DB::transaction(function () use ($booking, $actor, $minutes) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if ($booking->status !== 'active') {
                throw new RuntimeException('Продление только для активной сессии');
            }
            if ((int) $booking->user_id !== (int) $actor->id) {
                throw new RuntimeException('Сессия принадлежит другому игроку');
            }

            $pc = Computer::query()->with('space.zone')->find((int) $booking->computer_id);
            if (! $pc) {
                throw new RuntimeException('ПК не найден');
            }

            $tz = config('app.timezone');
            $now = CarbonImmutable::now($tz);
            $rate = $this->hourlyRateForComputer($pc, (int) $pc->club_id, $now);
            $cost = $this->costForMinutes($rate, $minutes);
            $endsAt = $this->currentEndsAt($booking, $now);
            $newEnds = $endsAt->addMinutes($minutes);
            $this->assertNoConflict($booking, $pc, $endsAt, $newEnds);

            $user = User::query()->lockForUpdate()->findOrFail((int) $actor->id);
            $user->syncBalanceToWallet();
            $balance = (float) $user->availableBalance();
            if ($balance + 0.009 < $cost) {
                $shortage = round($cost - $balance, 2);

                return [
                    'applied' => false,
                    'needs_topup' => true,
                    'minutes' => $minutes,
                    'cost' => $cost,
                    'balance' => $balance,
                    'shortage' => $shortage,
                    'suggested_topup' => $this->suggestedTopUp($shortage),
                    'message' => sprintf(
                        'Не хватает %.2f ₽ на продление на %s',
                        $shortage,
                        $this->labelForMinutes($minutes)
                    ),
                ];
            }

            $wallet = \App\Models\Wallet::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (! $wallet) {
                throw new RuntimeException('Кошелёк не найден');
            }
            $wallet->debitSpendable($cost);
            Transaction::create([
                'user_id' => $user->id,
                'amount' => -$cost,
                'type' => 'purchase',
                'source' => 'session_extend',
                'description' => sprintf(
                    'Продление сессии +%s (%s)',
                    $this->labelForMinutes($minutes),
                    $pc->name
                ),
                'payload' => [
                    'booking_id' => $booking->id,
                    'computer_id' => $pc->id,
                    'minutes' => $minutes,
                    'hourly_rate' => $rate,
                ],
            ]);

            $start = $booking->actual_started_at
                ? CarbonImmutable::parse($booking->actual_started_at, $tz)
                : ($booking->starts_at
                    ? CarbonImmutable::parse($booking->starts_at, $tz)
                    : $now);
            $durationHours = max(0.05, round($start->diffInSeconds($newEnds) / 3600, 2));

            $costRounded = (int) round($cost);
            $baseMinor = (int) ($booking->price_minor ?: ((int) $booking->price * 100));
            $booking->update([
                'ends_at' => $newEnds,
                'duration' => $durationHours,
                // bookings.price — integer (₽)
                'price' => (int) $booking->price + $costRounded,
                'price_minor' => $baseMinor + ($costRounded * 100),
            ]);

            $this->statuses->syncFor((int) $pc->id);
            $booking = $this->timing->healSkewedWindow($booking->fresh());

            return [
                'applied' => true,
                'needs_topup' => false,
                'minutes' => $minutes,
                'cost' => $cost,
                'balance' => (float) $user->fresh()->availableBalance(),
                'time_remaining' => $this->timing->formatRemainingHms($booking),
                'remaining_seconds' => $this->timing->remainingSeconds($booking),
                'ends_at' => CarbonImmutable::parse($booking->ends_at)->toIso8601String(),
                'message' => sprintf('Сессия продлена на %s', $this->labelForMinutes($minutes)),
            ];
        });
    }

    private function assertAllowedMinutes(int $minutes): void
    {
        if (! in_array($minutes, self::ALLOWED_MINUTES, true)) {
            throw new RuntimeException('Выберите 30 мин, 1, 2 или 3 часа');
        }
    }

    private function assertNoConflict(
        Booking $booking,
        Computer $pc,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): void {
        $occupied = $this->bookings->occupiedComputerIds(
            [(int) $pc->id],
            $from,
            $to,
            [(int) $booking->id]
        );
        if ($occupied !== []) {
            throw new RuntimeException('Место уже забронировано на это время');
        }
    }

    private function currentEndsAt(Booking $booking, CarbonImmutable $now): CarbonImmutable
    {
        if ($booking->ends_at) {
            $ends = CarbonImmutable::parse($booking->ends_at)->timezone(config('app.timezone'));
            // Если таймер уже на нуле, продлеваем от now.
            return $ends->greaterThan($now) ? $ends : $now;
        }

        return $now;
    }

    private function costForMinutes(float $hourlyRate, int $minutes): float
    {
        return round(max(0, $hourlyRate) * ($minutes / 60), 2);
    }

    private function suggestedTopUp(float $shortage): float
    {
        if ($shortage <= 0.009) {
            return 0.0;
        }
        // ЮKassa top-up min 100 ₽; округляем вверх до «красивой» суммы.
        $need = max(100.0, $shortage);
        foreach ([100, 200, 300, 500, 1000, 2000, 5000] as $step) {
            if ($step + 0.009 >= $need) {
                return (float) $step;
            }
        }

        return ceil($need / 100) * 100;
    }

    private function labelForMinutes(int $minutes): string
    {
        return match ($minutes) {
            30 => '30 мин',
            60 => '1 ч',
            120 => '2 ч',
            180 => '3 ч',
            default => $minutes.' мин',
        };
    }

    private function hourlyRateForComputer(Computer $pc, int $clubId, CarbonImmutable $at): float
    {
        $space = $pc->relationLoaded('space') ? $pc->space : Space::query()->find($pc->space_id);
        $zoneId = $space?->zone_id ? (int) $space->zone_id : null;
        $base = $this->tariffs->hourlyRateRub($clubId, $zoneId, $at);
        $surcharge = $space ? (float) $space->effectiveAlwaysSurchargePerHour($clubId) : 0.0;

        return round($base + max(0, $surcharge), 2);
    }
}
