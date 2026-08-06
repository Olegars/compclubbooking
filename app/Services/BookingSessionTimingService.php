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
        $paidMinutes = max(1, (int) round($scheduledStart->diffInMinutes($scheduledEnd)));
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
        $remaining = max(0, (int) floor($now->diffInSeconds($scheduledEnd, false) / 60));

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

        return [
            'booking' => $booking->fresh(),
            'time_remaining_minutes' => $remaining,
        ];
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
