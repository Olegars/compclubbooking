<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Booking;
use App\Models\Order; // 1. НЕ ЗАБУДЬ ДОБАВИТЬ ЭТОТ ИМПОРТ

class AccountController extends Controller
{
    public function dashboard()
    {

        $user = auth()->user();

        // Формируем красивые данные для истории
        $formattedTransactions = $user->transactions()
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'amount' => $tx->amount,
                    'description' => $tx->description,
                    'date' => $tx->created_at->format('d.m.Y H:i')
                ];
            });

        // 2. ПОЛУЧАЕМ АКТИВНЫЕ ЗАКАЗЫ ИЗ МАГАЗИНА
        $activeOrders = Order::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'cooking']) // Только те, что еще не принесли
            ->latest()
            ->get();

        // 3. ПЕРЕДАЕМ ВСЁ В ИНТЕРФЕЙС
        return Inertia::render('Account/Dashboard', [
            'transactions' => $formattedTransactions,
            'gizmo' => $user->wallet,
            'orders' => $activeOrders, // ВОТ ЭТА СТРОЧКА ОЖИВИТ КАРТОЧКУ
            'active_bookings' => Booking::where('user_id', $user->id)
                ->where('status', 'active')
                ->get()
        ]);
    }
}
