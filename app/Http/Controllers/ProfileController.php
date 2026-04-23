<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Booking;
use Inertia\Inertia;
use App\Models\Order;
use Carbon\Carbon;

class ProfileController extends Controller
{


    public function dashboard()
    {
        $user = Auth::user();
        $now = now();
        $today = $now->toDateString();

        $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        // 1. Получаем активные заказы (pending или cooking)
        $activeOrders = Order::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'cooking'])
            ->latest()
            ->get();

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

        $yesterday = now()->subDay()->toDateString();

        $activeBookings = Booking::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('date', '>=', $yesterday)
            ->get()
            ->filter(function($booking) use ($now) {
                $startTime = (float)$booking->start_time;
                $duration = (float)$booking->duration;
                $end = \Carbon\Carbon::parse($booking->date)
                    ->addMinutes($startTime * 60)
                    ->addHours($duration);
                return $now->lessThan($end);
            })
            ->values();

        return Inertia::render('User/Dashboard', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'avatar' => $user->avatar, // Добавь это, если хочешь чтобы аватарка обновлялась
            ],
            'gizmo' => [
                'balance' => (float)$wallet->balance,
                'spent_total' => (float)abs($user->transactions()->where('amount', '<', 0)->sum('amount')),
            ],
            'transactions' => $transactions,
            'active_bookings' => $activeBookings,
            'orders' => $activeOrders // ВОТ ЭТО ОЖИВИТ КАРТОЧКУ НА ДАШБОРДЕ
        ]);
    }
}
