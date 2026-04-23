<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Booking; // Тебе нужно создать модель Booking и миграцию
use App\Models\Transaction;

class BookingController extends Controller
{
    /**
     * Списание средств и резервация ПК
     */
    public function reserve(Request $request)
    {
        $request->validate([
            'pc_ids' => 'required|array',
            'price' => 'required|numeric',
            'date' => 'required|string',
            'start_h' => 'required|numeric',
            'duration' => 'required|numeric',
        ]);

        $user = auth()->user();
        $totalPrice = $request->price;

        // 1. Проверяем баланс
        if ($user->wallet->balance < $totalPrice) {
            return response()->json([
                'message' => 'Недостаточно средств. Пополните баланс.'
            ], 422);
        }

        // 2. Выполняем транзакцию
        return DB::transaction(function () use ($user, $request, $totalPrice) {

            // Списываем деньги из кошелька
            $user->wallet()->decrement('balance', $totalPrice);

            // Создаем лог транзакции
            Transaction::create([
                'user_id' => $user->id,
                'amount' => -$totalPrice,
                'type' => 'booking',
                'source' => 'balance',
                'description' => 'Бронь узла ' . implode(', ', $request->pc_ids),
                'date' => now()->format('d.m.Y H:i')
            ]);

            // Сохраняем бронь в БД (чтобы вывести на Дашборде)
            Booking::create([
                'user_id' => $user->id,
                'pc_ids' => json_encode($request->pc_ids),
                'date' => $request->date,
                'start_time' => $request->start_h,
                'duration' => $request->duration,
                'price' => $totalPrice,
                'status' => 'active'
            ]);

            return response()->json(['status' => 'success']);
        });
    }
}
