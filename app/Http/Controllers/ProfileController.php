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

                $receiptUrl = $fiscal->displayReceiptUrl($t);
                $isStub = $fiscal->isStubReceiptUrl($receiptUrl)
                    || ($t->fiscal_status === 'skipped' && filled($receiptUrl));

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
                ];
            });

        // 4. Активные бронирования (включая опоздание в soft-grace окне)
        $timing = app(BookingSessionTimingService::class);
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

                if ($started) {
                    $remainingSeconds = $timing->remainingSeconds($booking, $nowImmutable);
                    $isOverdue = false;
                } elseif ($waiting) {
                    $remainingSeconds = $paidMinutes * 60;
                    $isOverdue = false;
                } else {
                    $following = $timing->hasFollowingBookingConflict(
                        $booking,
                        $scheduledStart,
                        $scheduledEnd,
                        $paidMinutes
                    );
                    $remainingSeconds = $following
                        ? max(0, (int) floor($nowImmutable->diffInSeconds($scheduledEnd, false)))
                        : $timing->softGraceRemainingSeconds($scheduledStart, $paidMinutes, $nowImmutable);
                    $isOverdue = $remainingSeconds > 0;
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

                $effectiveEndMs = $waiting
                    ? $scheduledEnd->getTimestamp() * 1000
                    : ($nowImmutable->getTimestamp() + $remainingSeconds) * 1000;

                $booking->end_timestamp = $effectiveEndMs;
                $booking->start_timestamp = $scheduledStart->getTimestamp() * 1000;
                $booking->remaining_seconds = $remainingSeconds;
                $booking->is_overdue = $isOverdue;
                $booking->is_started = $started;
                $booking->is_expired = $remainingSeconds <= 0 && ! $waiting;
                $booking->formatted_pc = $pcLabel;
                $booking->game_titles = $titles;
                $booking->game_label = implode(', ', $titles);
                $booking->display_start = $scheduledStart->format('H:i');
                $booking->display_end = $scheduledEnd->format('H:i');

                $group = $booking->group;
                $canCancel = $group ? $bookingService->canUserCancel($group, $nowImmutable) : false;
                $cancelDeadline = $group ? $bookingService->cancelDeadlineFor($group) : null;
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
}
