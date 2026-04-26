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
        $yesterday = now()->subDay()->toDateString();

        // 1. Кошелек
        $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        // 2. Активные заказы из магазина (Снаряжение в пути)
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

        // 4. Активные бронирования (ИСПРАВЛЕНО)
        $activeBookings = Booking::where('user_id', $user->id)
            // Расширяем статусы, чтобы подхватить даже те, что еще не отмечены как 'active' админом
            ->whereIn('status', ['active', 'paid', 'confirmed', 'new'])
            ->where('date', '>=', $yesterday)
            ->get()
            ->filter(function($booking) use ($now) {
                $startTime = (float)$booking->start_time;
                $duration = (float)$booking->duration;

                // Создаем время окончания брони
                $end = Carbon::parse($booking->date)
                    ->startOfDay()
                    ->addMinutes($startTime * 60)
                    ->addHours($duration);

                // Бронь должна закончиться позже, чем "сейчас"
                return $now->lessThan($end);
            })
            ->map(function($booking) {
                // Гарантируем, что pc_ids дойдет до фронта в понятном виде
                // Если там строка JSON, оставляем как есть (фронт сам распарсит)
                return $booking;
            })
            ->values();

        // 5. Бонусы за отзывы
        $latestReview = ReviewClaim::where('user_id', $user->id)
            ->latest()
            ->first();

        // 6. Рендер интерфейса
        return Inertia::render('User/Dashboard', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
            ],
            'gizmo' => [
                'balance' => (float)$wallet->balance,
                'spent_total' => (float)abs($user->transactions()->where('amount', '<', 0)->sum('amount')),
            ],
            'transactions' => $transactions,
            'active_bookings' => $activeBookings,
            'orders' => $activeOrders,
            'latest_review' => $latestReview,
            // Передаем серверное время, чтобы таймеры на фронте не врали
            'server_time' => $now->toIso8601String(),
        ]);
    }
}
