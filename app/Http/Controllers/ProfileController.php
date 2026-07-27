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

class ProfileController extends Controller
{
    public function dashboard()
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
            ->map(function ($t) {
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

                return [
                    'id' => $t->id,
                    'amount' => (float) $t->amount,
                    'description' => $description,
                    'games' => $titles->all(),
                    'date' => $t->created_at->format('d.m / H:i'),
                ];
            });

        // 4. Активные бронирования (ЛОГИКА ИСПРАВЛЕНА)
        $activeBookings = Booking::where('user_id', $user->id)
            ->with([
                'computer:id,name',
                'gameReservations.bookingGame',
                'group.games',
            ])
            ->whereIn('status', ['active', 'paid', 'confirmed', 'new'])
            ->where('date', '>=', $yesterday)
            ->get()
            ->map(function ($booking) use ($now) {
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

                // Добавляем вычисленные поля для фронтенда
                $booking->end_timestamp = $endDateTime->getTimestamp() * 1000;
                $booking->is_expired = $now->greaterThan($endDateTime);
                $booking->formatted_pc = $pcLabel;
                $booking->games_titles = $titles;
                $booking->games_label = implode(', ', $titles);
                $booking->display_start = $startDateTime->format('H:i');
                $booking->display_end = $endDateTime->format('H:i');

                return $booking;
            })
            // Оставляем только те, что еще не закончились
            ->filter(fn ($b) => ! $b->is_expired)
            ->values();

        // 5. Бонусы
        $latestReview = ReviewClaim::where('user_id', $user->id)->latest()->first();

        // 6. Рендер (Все ключи приведены к соответствию с Vue)
        return Inertia::render('User/Dashboard', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
            ],
            'gizmo' => [
                'balance' => $user->availableBalance(),
                'spent_total' => (float) abs($user->transactions()->where('amount', '<', 0)->sum('amount')),
            ],
            'transactions' => $transactions,
            'active_bookings' => $activeBookings,
            'orders' => $activeOrders,
            'latest_review' => $latestReview,
            'server_time' => $now->toIso8601String(),
        ]);
    }
}
