<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Booking;
use Inertia\Inertia;
use App\Models\Order;
use Carbon\Carbon;
use App\Models\ReviewClaim;
use App\Services\AchievementService;
use App\Services\BookingSessionTimingService;
use App\Services\FiscalService;
use App\Services\GameBookingService;
use Carbon\CarbonImmutable;

class ProfileController extends Controller
{
    public function dashboard(FiscalService $fiscal)
    {
        $user = Auth::user();
        $now = now();
        // Берем брони за сегодня и вчера (на случай ночных смен)
        $yesterday = now()->subDay()->toDateString();

        // 1. Кошелек (единый spendable баланс для shell / shop / кабинета)
        $user->syncBalanceToWallet();
        $user->wallet()->firstOrCreate(
            ['user_id' => $user->id],
            ['deposit_balance' => 0]
        );

        // No-show / отложенные чеки до отрисовки истории (не ждать только cron).
        $timing = app(BookingSessionTimingService::class);
        $timing->cancelNoShows();
        $fiscal->settleOrphanedDeferredBookings();

        // 2. Активные заказы из магазина
        $activeOrders = Order::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'cooking', 'new', 'waiting'])
            ->latest()
            ->get();

        // 3. Последние транзакции
        $transactions = $user->transactions()
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($t) use ($fiscal) {
                $titles = collect(data_get($t->payload, 'games', []))
                    ->pluck('title')
                    ->filter()
                    ->unique()
                    ->values();

                $description = $t->description;
                if ($titles->isNotEmpty() && (
                    ! $description
                    || str_contains(mb_strtolower((string) $description), 'компьютеры и игры')
                    || preg_match('/^бронь #\d+:\s*компьютеры$/iu', (string) $description)
                )) {
                    $description = 'Бронь #'.($t->booking_group_id ?: '—').': '.$titles->implode(', ');
                }

                // В чеке/логе — забронированные часы из quote, не remaining после activate.
                $bookedHours = $fiscal->bookedHoursFromTransaction($t);
                $hoursLabel = $fiscal->formatHoursLabel($bookedHours);
                if ($hoursLabel && preg_match('/·\s*[\d.,]+\s*ч/u', (string) $description)) {
                    $description = preg_replace('/·\s*[\d.,]+\s*ч/u', '· '.$hoursLabel, (string) $description, 1);
                }

                $receiptUrl = $fiscal->displayReceiptUrl($t);
                $isStub = $fiscal->isStubReceiptUrl($receiptUrl)
                    || ($t->fiscal_status === 'skipped' && filled($receiptUrl));

                $isNoShow = false;
                if (
                    $t->booking_group_id
                    && in_array((string) $t->type, ['booking', 'booking_upgrade'], true)
                ) {
                    $group = \App\Models\BookingGroup::query()->find($t->booking_group_id);
                    if (
                        $group
                        && $group->status === 'cancelled'
                        && $group->payment_status !== 'refunded'
                        && ! $group->bookings()->whereNotNull('actual_started_at')->exists()
                    ) {
                        $isNoShow = true;
                    }
                }

                return [
                    'id' => $t->id,
                    'type' => $t->type,
                    'amount' => (float) $t->amount,
                    'description' => $description,
                    'games' => $titles->all(),
                    'date' => $t->created_at->format('d.m / H:i'),
                    'fiscal_receipt_url' => $receiptUrl,
                    'fiscal_status' => $t->fiscal_status ?: ($isStub ? 'skipped' : null),
                    'payment_uuid' => data_get($t->payload, 'payment_uuid'),
                    'has_receipt' => filled($receiptUrl),
                    'is_stub_receipt' => $isStub,
                    'is_no_show' => $isNoShow,
                ];
            });

        // 4. Активные бронирования (включая опоздание в soft-grace окне)
        $bookingService = app(GameBookingService::class);
        $nowImmutable = CarbonImmutable::instance($now->copy()->timezone(config('app.timezone')));

        $activeBookings = Booking::where('user_id', $user->id)
            ->with([
                'computer:id,name',
                'gameReservations.bookingGame',
                'group.games',
            ])
            ->whereIn('status', ['active', 'paid', 'confirmed', 'new'])
            ->where('date', '>=', $yesterday)
            ->get()
            ->map(function ($booking) use ($nowImmutable, $timing, $bookingService) {
                $tz = config('app.timezone');
                $startTime = (float) $booking->start_time;
                $duration = (float) $booking->duration;

                // Для карточки в кабинете опираемся на wall-clock (date + start_time),
                // который совпадает с выбором пользователя на экране бронирования.
                // starts_at/ends_at могут быть сдвинуты из-за naive timestamp в PG.
                $dateString = $booking->date instanceof \DateTimeInterface
                    ? $booking->date->format('Y-m-d')
                    : Carbon::parse($booking->date)->format('Y-m-d');

                $startDateTime = Carbon::parse($dateString, $tz)
                    ->startOfDay()
                    ->addMinutes((int) round($startTime * 60));
                $endDateTime = (clone $startDateTime)->addMinutes((int) round($duration * 60));

                // Если modern-поля согласованы с wall-clock — используем их (точнее до секунд).
                if ($booking->starts_at && $booking->ends_at) {
                    $modernStart = Carbon::parse($booking->starts_at)->timezone($tz);
                    $modernEnd = Carbon::parse($booking->ends_at)->timezone($tz);
                    if (abs($modernStart->diffInMinutes($startDateTime)) <= 1) {
                        $startDateTime = $modernStart;
                        $endDateTime = $modernEnd;
                    }
                }

                $scheduledStart = CarbonImmutable::instance($startDateTime);
                $scheduledEnd = CarbonImmutable::instance($endDateTime);
                $paidMinutes = $timing->paidDurationMinutes($booking, $scheduledStart, $scheduledEnd);
                $started = filled($booking->actual_started_at);
                $waiting = $nowImmutable->lt($scheduledStart);

                // phase: waiting | late_waiting | late_billing | active
                $phase = 'waiting';
                $billingStart = $scheduledStart;

                if ($started) {
                    $remainingSeconds = $timing->remainingSeconds($booking, $nowImmutable);
                    $phase = 'active';
                    $effectiveEndMs = ($nowImmutable->getTimestamp() + $remainingSeconds) * 1000;
                } elseif ($waiting) {
                    $following = $timing->hasFollowingBookingConflict(
                        $booking,
                        $scheduledStart,
                        $scheduledEnd,
                        $paidMinutes
                    );
                    $graceMinutes = $following ? 0 : $timing->lateStartGraceMinutes();
                    $billingStart = $scheduledStart->addMinutes($graceMinutes);
                    $absoluteEnd = $billingStart->addMinutes($paidMinutes);
                    $remainingSeconds = $paidMinutes * 60;
                    $phase = 'waiting';
                    $effectiveEndMs = $absoluteEnd->getTimestamp() * 1000;
                } else {
                    $following = $timing->hasFollowingBookingConflict(
                        $booking,
                        $scheduledStart,
                        $scheduledEnd,
                        $paidMinutes
                    );
                    $graceMinutes = $following ? 0 : $timing->lateStartGraceMinutes();
                    $billingStart = $scheduledStart->addMinutes($graceMinutes);
                    $absoluteEnd = $billingStart->addMinutes($paidMinutes);
                    $remainingSeconds = max(0, (int) floor($nowImmutable->diffInSeconds($absoluteEnd, false)));
                    $phase = $nowImmutable->lt($billingStart) ? 'late_waiting' : 'late_billing';
                    $effectiveEndMs = $absoluteEnd->getTimestamp() * 1000;
                }

                // Обрабатываем pc_ids (Postgres часто отдает строку вместо массива)
                $pcIds = $booking->pc_ids;
                if (is_string($pcIds)) {
                    $pcIds = json_decode($pcIds, true) ?: [$pcIds];
                }

                $pcLabel = $booking->computer?->name
                    ?: implode(', ', array_filter((array) $pcIds));

                $titles = [];
                foreach ($booking->gameReservations as $reservation) {
                    $title = optional($reservation->bookingGame)->game_title;
                    if (is_string($title) && $title !== '') {
                        $titles[] = $title;
                    }
                }
                if ($titles === [] && $booking->group) {
                    foreach ($booking->group->games as $bookingGame) {
                        $title = $bookingGame->game_title;
                        if (is_string($title) && $title !== '') {
                            $titles[] = $title;
                        }
                    }
                }
                $titles = array_values(array_unique($titles));

                $booking->end_timestamp = $effectiveEndMs;
                $booking->start_timestamp = $scheduledStart->getTimestamp() * 1000;
                $booking->billing_start_timestamp = $billingStart->getTimestamp() * 1000;
                $booking->remaining_seconds = $remainingSeconds;
                $booking->phase = $phase;
                $booking->is_late_waiting = $phase === 'late_waiting';
                $booking->is_late_billing = $phase === 'late_billing';
                $booking->is_started = $started;
                $booking->is_expired = $remainingSeconds <= 0 && ! $waiting;
                $booking->formatted_pc = $pcLabel;
                $booking->game_titles = $titles;
                $booking->game_label = implode(', ', $titles);
                $booking->display_start = $scheduledStart->format('H:i');
                $booking->display_end = $scheduledEnd->format('H:i');

                $group = $booking->group;
                $canCancel = $group
                    ? $bookingService->canUserCancel($group, $nowImmutable, $scheduledStart)
                    : false;
                $cancelDeadline = $group
                    ? $bookingService->cancelDeadlineFor($group, $scheduledStart)
                    : null;
                $booking->can_cancel = $canCancel;
                $booking->cancel_deadline_at = $cancelDeadline?->toIso8601String();
                $booking->cancel_before_minutes = $bookingService->cancelBeforeMinutes();

                return $booking;
            })
            // Будущие + идущие + опоздание, пока ещё можно войти
            ->filter(fn ($b) => ! $b->is_expired)
            ->values();

        // 5. Бонусы за отзыв (pending имеет приоритет для статуса в UI)
        $latestReview = ReviewClaim::where('user_id', $user->id)
            ->where('status', ReviewClaim::STATUS_PENDING)
            ->latest()
            ->first()
            ?? ReviewClaim::where('user_id', $user->id)->latest()->first();

        $reviewMeta = app(\App\Services\ReviewBonusService::class)->clientMeta();

        // 6. Квесты / ачивки
        $achievements = app(AchievementService::class)->progressForUser($user);

        // 7. Рендер (Все ключи приведены к соответствию с Vue)
        return Inertia::render('User/Dashboard', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
            ],
            'transactions' => $transactions,
            'active_bookings' => $activeBookings,
            'orders' => $activeOrders,
            'latest_review' => $latestReview,
            'review_meta' => $reviewMeta,
            'achievements' => $achievements,
            'server_time' => $now->toIso8601String(),
        ]);
    }

    public function edit()
    {
        return Inertia::render('User/Profile');
    }

    public function update(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
        ]);

        return back();
    }

    public function transferTargets(\App\Services\BookingSeatTransferService $transfers)
    {
        $user = Auth::user();
        $booking = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (! $booking) {
            return response()->json(['message' => 'Нет активной сессии'], 404);
        }

        $map = $transfers->mapForTransfer($booking);

        return response()->json([
            'status' => 'success',
            'booking_id' => $booking->id,
            'from_computer_id' => $map['from_computer_id'],
            'targets' => $map['targets'],
            'map_config' => $map['map_config'],
            'computers' => $map['computers'],
            'occupied_ids' => $map['occupied_ids'],
            'selectable_ids' => $map['selectable_ids'],
        ]);
    }

    public function transferPreview(Request $request, \App\Services\BookingSeatTransferService $transfers)
    {
        $user = Auth::user();
        $data = $request->validate([
            'target_computer_id' => 'required|integer',
        ]);

        $booking = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (! $booking) {
            return response()->json(['message' => 'Нет активной сессии'], 404);
        }

        try {
            $preview = $transfers->preview($booking, (int) $data['target_computer_id']);

            return response()->json(['status' => 'success', 'preview' => $preview]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function transferConfirm(Request $request, \App\Services\BookingSeatTransferService $transfers)
    {
        $user = Auth::user();
        $data = $request->validate([
            'target_computer_id' => 'required|integer',
        ]);

        $booking = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (! $booking) {
            return response()->json(['message' => 'Нет активной сессии'], 404);
        }

        try {
            $result = $transfers->transfer($booking, (int) $data['target_computer_id'], $user);

            return response()->json([
                'status' => 'success',
                'message' => 'Пересадка выполнена. Войдите PIN на новом ПК.',
                'pin_code' => $result['pin_code'] ?? null,
                'to' => $result['to'] ?? null,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
