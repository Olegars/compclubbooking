<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GizmoController extends Controller
{
    /**
     * Получить полные данные пользователя (баланс, время, статус)
     */
    public function getUserProfile()
    {
        // Имитируем ответ от Gizmo API
        return response()->json([
            'success' => true,
            'data' => [
                'userId' => Auth::id() ?? 101,
                'username' => Auth::user()->name ?? 'Stalker_0451',
                'balance' => 750.50,
                'bonusBalance' => 100.00,
                'totalSpent' => 5400.00,
                'status' => 'Active',
                'groupName' => 'Standard Zone',
                'remainingTime' => 125, // в минутах
                'isInvoiced' => false,
            ]
        ]);
    }

    /**
     * Получить актуальные статусы всех компьютеров
     */
    public function getComputersStatus()
    {
        // Имитируем данные о хостах
        return response()->json([
            'success' => true,
            'computers' => [
                ['id' => 85, 'status' => 'Available', 'user' => null],
                ['id' => 86, 'status' => 'Occupied', 'user' => 'Gamer_1'],
                ['id' => 87, 'status' => 'Available', 'user' => null],
                ['id' => 88, 'status' => 'Maintenance', 'user' => null],
                ['id' => 89, 'status' => 'Occupied', 'user' => 'Reactor_Fan'],
            ]
        ]);
    }

    /**
     * Запуск игровой сессии (логин на компьютере)
     */
    public function startSession(Request $request)
    {
        $pcId = $request->input('pc_id');

        // Имитируем успешный старт
        return response()->json([
            'success' => true,
            'message' => "Сессия на ПК №$pcId успешно запущена",
            'startTime' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Принудительное завершение сессии
     */
    public function stopSession(Request $request)
    {
        return response()->json([
            'success' => true,
            'message' => 'Сессия завершена. Время сохранено.',
        ]);
    }

    /**
     * История транзакций и чеков
     */
    public function getTransactionHistory()
    {
        return response()->json([
            'success' => true,
            'transactions' => [
                [
                    'id' => 501,
                    'date' => now()->subDays(1)->format('d.m.Y H:i'),
                    'amount' => -250,
                    'description' => 'Пакет "Ночь PRO"',
                    'type' => 'Usage'
                ],
                [
                    'id' => 488,
                    'date' => now()->subDays(2)->format('d.m.Y H:i'),
                    'amount' => 1000,
                    'description' => 'Пополнение баланса (Терминал)',
                    'type' => 'Deposit'
                ],
            ]
        ]);
    }

    /**
     * Пополнение баланса (внутренний метод)
     */
    public function deposit(Request $request)
    {
        $amount = $request->input('amount');

        return response()->json([
            'success' => true,
            'newBalance' => 750.50 + $amount,
            'message' => "Баланс успешно пополнен на $amount руб."
        ]);
    }
}
