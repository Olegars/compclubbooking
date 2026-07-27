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

        // 1. Кошелек
        $wallet = $user->wallet()->firstOrCreate(
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
            ->map(function($t) {
                return [
                    'id' => $t->id,
                    'amount' => (float)$t->amount,
                    'description' => $t->description,
                    'date' => $t->created_at->format('d.m / H:i'),
                ];
            });

        // 4. Активные бронирования (ЛОГИКА ИСПРАВЛЕНА)
        $activeBookings = Booking::where('user_id', $user->id)
            ->whereIn('status', ['active', 'paid', 'confirmed', 'new'])
            ->where('date', '>=', $yesterday)
            ->get()
            ->map(function($booking) use ($now) {
                $startTime = (float)$booking->start_time;
                $duration = (float)$booking->duration;

                // Считаем точное время старта и конца на сервере
                $startDateTime = Carbon::parse($booking->date)->startOfDay()->addMinutes($startTime * 60);
                $endDateTime = (clone $startDateTime)->addHours($duration);

                // Обрабатываем pc_ids (Postgres часто отдает строку вместо массива)
                $pcIds = $booking->pc_ids;
                if (is_string($pcIds)) {
                    $pcIds = json_decode($pcIds, true) ?: [$pcIds];
                }

                // Добавляем вычисленные поля для фронтенда
                $booking->end_timestamp = $endDateTime->timestamp * 1000; // в миллисекундах для JS
                $booking->is_expired = $now->greaterThan($endDateTime);
                $booking->formatted_pc = implode(', ', (array)$pcIds);

                return $booking;
            })
            // Оставляем только те, что еще не закончились
            ->filter(fn($b) => !$b->is_expired)
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
                'spent_total' => (float)abs($user->transactions()->where('amount', '<', 0)->sum('amount')),
            ],
            'transactions' => $transactions,
            'active_bookings' => $activeBookings,
            'orders' => $activeOrders,
            'latest_review' => $latestReview,
            'server_time' => $now->toIso8601String(),
        ]);
    }
}
