<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\GameAccount;
use App\Models\GameAccountReservation;
use App\Services\Fan\FanControlService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BookingSessionTimingService
{
    public function __construct(
        private readonly GameBookingService $bookings,
        private readonly ComputerStatusService $computerStatuses,
    ) {
    }

    public function lateStartGraceMinutes(): int
    {
        return max(0, (int) config('club.booking.late_start_grace_minutes', 15));
    }

    /**
     * Remaining session time for shell / UI.
     * Prefer wall-clock (date + start_time + duration) when starts_at/ends_at look
     * timezone-shifted vs the booking card the user paid for (naive PG timestamps).
     */
    public function remainingSeconds(Booking $booking, ?CarbonImmutable $now = null): int
    {
        $now = $now ?? CarbonImmutable::now();
        $end = $this->effectiveEndsAt($booking);

        return max(0, (int) floor($now->diffInSeconds($end, false)));
    }

    public function formatRemainingHms(Booking $booking, ?CarbonImmutable $now = null): string
    {
        $secs = $this->remainingSeconds($booking, $now);
        $h = intdiv($secs, 3600);
        $m = intdiv($secs % 3600, 60);
        $s = $secs % 60;

        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    /**
     * Paid length in minutes — trust duration when window disagrees (tz skew).
     */
    public function paidDurationMinutes(
        Booking $booking,
        ?CarbonImmutable $scheduledStart = null,
        ?CarbonImmutable $scheduledEnd = null
    ): int {
        $fromDuration = (int) round(((float) $booking->duration) * 60);
        if ($scheduledStart && $scheduledEnd) {
            $fromWindow = (int) round(abs($scheduledStart->diffInMinutes($scheduledEnd)));
            if ($fromDuration > 0 && $fromWindow > 0 && abs($fromDuration - $fromWindow) > 2) {
                return max(1, $fromDuration);
            }
            if ($fromWindow > 0) {
                return max(1, $fromWindow);
            }
        }

        return max(1, $fromDuration > 0 ? $fromDuration : 1);
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function wallClockWindow(Booking $booking): array
    {
        $tz = config('app.timezone');
        $startTime = (float) $booking->start_time;
        $duration = (float) $booking->duration;
        $dateString = $booking->date instanceof \DateTimeInterface
            ? $booking->date->format('Y-m-d')
            : CarbonImmutable::parse((string) $booking->date, $tz)->format('Y-m-d');

        $start = CarbonImmutable::parse($dateString, $tz)
            ->startOfDay()
            ->addMinutes((int) round($startTime * 60));
        $end = $start->addMinutes((int) round(max(0.0, $duration) * 60));

        return ['start' => $start, 'end' => $end];
    }

    public function effectiveEndsAt(Booking $booking): CarbonImmutable
    {
        $wall = $this->wallClockWindow($booking);
        if (! $booking->starts_at || ! $booking->ends_at) {
            return $wall['end'];
        }

        $tz = config('app.timezone');
        $modernStart = CarbonImmutable::parse($booking->starts_at)->timezone($tz);
        $modernEnd = CarbonImmutable::parse($booking->ends_at)->timezone($tz);

        // After early-start activate, wall-clock fields are rewritten to match modern.
        // If modern start drifts from wall-clock by >2 min, trust duration-based end
        // (same rule as ProfileController dashboard card).
        if (abs($modernStart->diffInMinutes($wall['start'])) <= 2) {
            return $modernEnd;
        }

        // Active session with intentional shift: actual_started_at set and duration ok —
        // still prefer modern end only when it matches paid duration length.
        if ($booking->actual_started_at) {
            $paid = $this->paidDurationMinutes($booking);
            $modernLen = (int) round(abs(
                CarbonImmutable::parse($booking->actual_started_at)->timezone($tz)
                    ->diffInMinutes($modernEnd)
            ));
            if (abs($modernLen - $paid) <= 2) {
                return $modernEnd;
            }
        }

        return $wall['end'];
    }

    /**
     * Activate a confirmed booking at shell login.
     *
     * Early start (PC free): shift starts_at/ends_at to preserve paid duration.
     * On-time / late within grace: keep ends_at, remaining = now→ends_at.
     * Past grace without start: cancel as no-show.
     *
     * @return array{booking: Booking, time_remaining_minutes: int}
     */
    public function activate(Booking $booking, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();

        return DB::transaction(function () use ($booking, $now) {
            /** @var Booking $booking */
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! in_array($booking->status, ['paid', 'confirmed', 'active'], true)) {
                throw new RuntimeException('Бронь недоступна для входа.');
            }

            if ($booking->status === 'active' && $booking->actual_started_at) {
                throw new RuntimeException('Сессия уже была активирована.');
            }

            if (! $booking->starts_at || ! $booking->ends_at) {
                return $this->activateLegacy($booking, $now);
            }

            // Heal timezone-skewed modern window against wall-clock duration.
            $booking = $this->healSkewedWindow($booking);

            $scheduledStart = CarbonImmutable::parse($booking->starts_at);
            $scheduledEnd = CarbonImmutable::parse($booking->ends_at);

            if ($now->gte($scheduledEnd)) {
                throw new RuntimeException('Время брони уже закончилось.');
            }

            $graceDeadline = $scheduledStart->addMinutes($this->lateStartGraceMinutes());

            if ($now->lt($scheduledStart)) {
                return $this->activateEarly($booking, $now, $scheduledStart, $scheduledEnd);
            }

            if ($now->gt($graceDeadline)) {
                $this->markNoShow($booking, $now);
                throw new RuntimeException('Бронь аннулирована из‑за опоздания.');
            }

            return $this->activateOnSchedule($booking, $now, $scheduledEnd);
        }, 3);
    }

    /**
     * Cancel confirmed/paid bookings that passed late-start grace without activation.
     */
    public function cancelNoShows(?CarbonImmutable $now = null): int
    {
        $now = $now ?? CarbonImmutable::now();
        $grace = $this->lateStartGraceMinutes();
        $deadline = $now->subMinutes($grace);

        $ids = Booking::query()
            ->whereIn('status', ['confirmed', 'paid'])
            ->whereNull('actual_started_at')
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->where('starts_at', '<=', $deadline)
            ->where('ends_at', '>', $now)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $now, &$count) {
                $booking = Booking::query()->lockForUpdate()->find($id);
                if (! $booking || ! in_array($booking->status, ['confirmed', 'paid'], true)) {
                    return;
                }
                if ($booking->actual_started_at) {
                    return;
                }

                $this->markNoShow($booking, $now);
                $count++;
            }, 3);
        }

        return $count;
    }

    /**
     * Закрывает брони, у которых ends_at уже прошёл (или legacy-окно истекло).
     * Возвращает число закрытых booking id.
     */
    public function completeExpiredSessions(?CarbonImmutable $now = null): int
    {
        $now = $now ?? CarbonImmutable::now();
        $nowIso = $now->utc()->toIso8601String();
        $today = $now->timezone(config('app.timezone'))->toDateString();
        $local = $now->timezone(config('app.timezone'));
        $nowH = $local->hour + ($local->minute / 60);

        // Eloquent where('ends_at', '<=', $now) на timestamptz в PG даёт ложные промахи —
        // сравниваем через ::timestamptz.
        $expiredIds = Booking::query()
            ->whereIn('status', ['confirmed', 'active'])
            ->where(function ($query) use ($nowIso, $today, $nowH) {
                $query->where(function ($modern) use ($nowIso) {
                    $modern->whereNotNull('ends_at')
                        ->whereRaw('ends_at <= ?::timestamptz', [$nowIso]);
                })->orWhere(function ($legacy) use ($today, $nowH) {
                    $legacy->whereNull('ends_at')
                        ->where('date', '<=', $today)
                        ->whereRaw('(start_time + duration) <= ?', [$nowH]);
                });
            })
            ->pluck('id');

        if ($expiredIds->isEmpty()) {
            return 0;
        }

        $expiredBookings = Booking::query()->whereIn('id', $expiredIds)->get();

        Booking::query()
            ->whereIn('id', $expiredIds)
            ->update([
                'status' => 'completed',
                'actual_ended_at' => $now,
            ]);

        $reservations = GameAccountReservation::query()
            ->whereIn('booking_id', $expiredIds)
            ->whereIn('status', ['held', 'confirmed', 'active'])
            ->get();

        foreach ($reservations as $reservation) {
            $booking = $expiredBookings->firstWhere('id', $reservation->booking_id);
            GameAccount::query()
                ->whereKey($reservation->game_account_id)
                ->where('current_pc_id', $booking?->computer_id)
                ->update(['status' => 'free', 'current_pc_id' => null]);
            $reservation->update(['status' => 'completed', 'released_at' => $now]);
        }

        Booking::query()
            ->whereNull('starts_at')
            ->where('date', '<=', $today)
            ->where('status', 'active')
            ->whereRaw('(start_time + duration) <= ?', [$nowH])
            ->update([
                'status' => 'completed',
                'actual_ended_at' => $now,
            ]);

        BookingGroup::query()
            ->whereIn('status', ['confirmed', 'active'])
            ->whereRaw('ends_at <= ?::timestamptz', [$nowIso])
            ->update(['status' => 'completed']);

        $this->computerStatuses->syncFor($expiredBookings->pluck('computer_id'), $now);

        foreach ($expiredBookings->pluck('computer_id')->unique()->filter() as $computerId) {
            try {
                app(FanControlService::class)->reconcileForComputer((int) $computerId);
            } catch (\Throwable $e) {
                Log::warning('Fan reconcile after session expiry failed: '.$e->getMessage(), [
                    'computer_id' => $computerId,
                ]);
            }

            try {
                $pc = \App\Models\Computer::query()->find((int) $computerId);
                if ($pc && $pc->kind === \App\Models\Computer::KIND_TV) {
                    \App\Http\Controllers\Api\ShellIsolateRelayController::queueIsolate(
                        (int) $computerId,
                        ['reason' => 'session_expired']
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('TV isolate after session expiry failed: '.$e->getMessage(), [
                    'computer_id' => $computerId,
                ]);
            }
        }

        try {
            app(AchievementService::class)->evaluateForBookings($expiredBookings);
        } catch (\Throwable $e) {
            Log::warning('Achievement evaluate after session expiry failed: '.$e->getMessage());
        }

        return $expiredIds->count();
    }

    /**
     * @return array{booking: Booking, time_remaining_minutes: int}
     */
    private function activateEarly(
        Booking $booking,
        CarbonImmutable $now,
        CarbonImmutable $scheduledStart,
        CarbonImmutable $scheduledEnd
    ): array {
        $paidMinutes = $this->paidDurationMinutes($booking, $scheduledStart, $scheduledEnd);
        $newEndsAt = $now->addMinutes($paidMinutes);

        $computerId = (int) $booking->computer_id;
        $occupied = $this->bookings->occupiedComputerIds(
            [$computerId],
            $now,
            $newEndsAt,
            [(int) $booking->id]
        );

        if ($occupied !== []) {
            throw new RuntimeException('Компьютер ещё занят. Можно начать, когда место освободится.');
        }

        if ($this->gameAccountWindowConflicts($booking, $now, $newEndsAt)) {
            throw new RuntimeException('Игровой аккаунт ещё занят. Можно начать, когда место освободится.');
        }

        $localStart = $now->timezone(config('app.timezone'));
        $booking->update([
            'status' => 'active',
            'actual_started_at' => $now,
            'pin_code' => null,
            'starts_at' => $now,
            'ends_at' => $newEndsAt,
            'date' => $localStart->toDateString(),
            'start_time' => $localStart->hour + ($localStart->minute / 60),
            'duration' => $paidMinutes / 60,
        ]);

        $this->shiftReservations($booking, $now, $newEndsAt);
        $this->syncGroupWindow($booking, 'active');
        $this->computerStatuses->syncFor($computerId, $now);

        return [
            'booking' => $booking->fresh(),
            'time_remaining_minutes' => $paidMinutes,
        ];
    }

    /**
     * @return array{booking: Booking, time_remaining_minutes: int}
     */
    private function activateOnSchedule(
        Booking $booking,
        CarbonImmutable $now,
        CarbonImmutable $scheduledEnd
    ): array {
        $booking->update([
            'status' => 'active',
            'actual_started_at' => $now,
            'pin_code' => null,
        ]);

        GameAccountReservation::query()
            ->where('booking_id', $booking->id)
            ->whereIn('status', ['held', 'confirmed'])
            ->update([
                'status' => 'active',
                'activated_at' => $now,
            ]);

        $booking->group?->update(['status' => 'active']);
        $this->computerStatuses->syncFor((int) $booking->computer_id, $now);

        $fresh = $booking->fresh();

        return [
            'booking' => $fresh,
            'time_remaining_minutes' => max(0, (int) floor($this->remainingSeconds($fresh, $now) / 60)),
        ];
    }

    /**
     * If starts_at/ends_at disagree with date+start_time+duration by a timezone-sized gap,
     * rewrite modern fields from wall-clock (source of truth for the booking card).
     */
    public function healSkewedWindow(Booking $booking): Booking
    {
        if (! $booking->starts_at || ! $booking->ends_at) {
            return $booking;
        }

        $wall = $this->wallClockWindow($booking);
        $tz = config('app.timezone');
        $modernStart = CarbonImmutable::parse($booking->starts_at)->timezone($tz);
        $modernEnd = CarbonImmutable::parse($booking->ends_at)->timezone($tz);

        $startSkew = abs($modernStart->diffInMinutes($wall['start']));
        $endSkew = abs($modernEnd->diffInMinutes($wall['end']));
        $lenModern = (int) round(abs($modernStart->diffInMinutes($modernEnd)));
        $lenWall = (int) round(abs($wall['start']->diffInMinutes($wall['end'])));

        // Typical naive-timestamp bug: ~180 min (MSK) skew, duration field still correct.
        if ($startSkew <= 2 && $endSkew <= 2 && abs($lenModern - $lenWall) <= 2) {
            return $booking;
        }

        if ($lenWall < 1) {
            return $booking;
        }

        Log::warning('Healing skewed booking window', [
            'booking_id' => $booking->id,
            'start_skew_min' => $startSkew,
            'end_skew_min' => $endSkew,
            'len_modern' => $lenModern,
            'len_wall' => $lenWall,
            'wall_start' => $wall['start']->toIso8601String(),
            'wall_end' => $wall['end']->toIso8601String(),
        ]);

        $booking->update([
            'starts_at' => $wall['start'],
            'ends_at' => $wall['end'],
        ]);

        return $booking->fresh() ?? $booking;
    }

    /**
     * @return array{booking: Booking, time_remaining_minutes: int}
     */
    private function activateLegacy(Booking $booking, CarbonImmutable $now): array
    {
        $durationMinutes = (int) max(0, round(((float) $booking->duration) * 60));

        $booking->update([
            'status' => 'active',
            'actual_started_at' => $now,
            'pin_code' => null,
        ]);
        $booking->group?->update(['status' => 'active']);
        $this->computerStatuses->syncFor((int) $booking->computer_id, $now);

        return [
            'booking' => $booking->fresh(),
            'time_remaining_minutes' => $durationMinutes,
        ];
    }

    private function markNoShow(Booking $booking, CarbonImmutable $now): void
    {
        $accountIds = GameAccountReservation::query()
            ->where('booking_id', $booking->id)
            ->pluck('game_account_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $booking->update([
            'status' => 'cancelled',
            'pin_code' => null,
        ]);

        GameAccountReservation::query()
            ->where('booking_id', $booking->id)
            ->whereIn('status', ['held', 'confirmed', 'active'])
            ->update([
                'status' => 'cancelled',
                'released_at' => $now,
            ]);

        if ($accountIds !== []) {
            GameAccount::query()
                ->whereIn('id', $accountIds)
                ->where('current_pc_id', $booking->computer_id)
                ->update(['status' => 'free', 'current_pc_id' => null]);
        }

        $this->computerStatuses->syncFor((int) $booking->computer_id, $now);

        $group = $booking->group;
        if (! $group) {
            return;
        }

        $open = $group->bookings()
            ->whereIn('status', ['confirmed', 'paid', 'active', 'pending', 'pending_payment'])
            ->exists();

        if (! $open) {
            $group->update([
                'status' => 'cancelled',
                'cancelled_at' => $now,
            ]);
        }

        Log::info('Booking no-show cancelled', [
            'booking_id' => $booking->id,
            'booking_group_id' => $group->id,
            'computer_id' => $booking->computer_id,
        ]);
    }

    private function shiftReservations(
        Booking $booking,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt
    ): void {
        GameAccountReservation::query()
            ->where('booking_id', $booking->id)
            ->whereIn('status', ['held', 'confirmed', 'active'])
            ->update([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'active',
                'activated_at' => $startsAt,
            ]);
    }

    private function gameAccountWindowConflicts(
        Booking $booking,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt
    ): bool {
        $accountIds = GameAccountReservation::query()
            ->where('booking_id', $booking->id)
            ->whereIn('status', ['held', 'confirmed', 'active'])
            ->pluck('game_account_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($accountIds === []) {
            return false;
        }

        return GameAccountReservation::query()
            ->whereIn('game_account_id', $accountIds)
            ->where('booking_id', '!=', $booking->id)
            ->whereIn('status', ['held', 'confirmed', 'active'])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }

    private function syncGroupWindow(Booking $booking, string $status): void
    {
        $group = $booking->group;
        if (! $group) {
            return;
        }

        $siblings = $group->bookings()
            ->whereIn('status', ['confirmed', 'paid', 'active'])
            ->get(['starts_at', 'ends_at']);

        $payload = ['status' => $status];
        if ($siblings->isNotEmpty()) {
            $starts = $siblings->pluck('starts_at')->filter();
            $ends = $siblings->pluck('ends_at')->filter();
            if ($starts->isNotEmpty()) {
                $payload['starts_at'] = $starts->min();
            }
            if ($ends->isNotEmpty()) {
                $payload['ends_at'] = $ends->max();
            }
        }

        $group->update($payload);
    }
}
