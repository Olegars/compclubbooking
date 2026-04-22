<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\GizmoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    /**
     * Пополнение баланса (Обработка формы с дашборда)
     */
    public function topUp(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:100',
        ]);

        $user = $request->user();
        $amount = (float) $request->amount;

        try {
            // В будущем здесь будет вызов API эквайринга (ЮKassa и т.д.).
            // Пока мы просто симулируем успешное пополнение.

            DB::transaction(function () use ($user, $amount) {
                // 1. Начисляем деньги в кошелек
                $user->wallet()->firstOrCreate([])->increment('balance', $amount);

                // 2. Записываем в историю
                Transaction::create([
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'type' => 'deposit',
                    'source' => 'system', // Потом будет 'yookassa' или 'sbp'
                    'description' => 'Пополнение баланса REACTOR',
                ]);
            });

            return response()->json([
                'message' => 'Баланс успешно пополнен',
                'new_balance' => $user->wallet->balance
            ]);

        } catch (\Exception $e) {
            Log::error("Ошибка пополнения баланса юзера {$user->id}: " . $e->getMessage());
            return response()->json(['message' => 'Ошибка обработки платежа'], 500);
        }
    }

    /**
     * Запуск игровой сессии (Списание + Команда в Gizmo)
     */
    public function startSession(Request $request, GizmoService $gizmo)
    {
        $request->validate([
            'hostId' => 'required|integer', // Номер/ID компа
            'minutes' => 'required|integer|min:30',
            'price' => 'required|numeric|min:0',
        ]);

        $user = $request->user();
        $cost = (float) $request->price;
        $wallet = $user->wallet()->firstOrCreate([]);

        // Проверка баланса перед стартом
        if ($wallet->balance < $cost) {
            return response()->json([
                'message' => 'Недостаточно средств на балансе. Требуется: ' . $cost . '₽'
            ], 402); // 402 Payment Required
        }

        try {
            // Стартуем транзакцию. Если что-то упадет внутри, деньги не спишутся.
            DB::transaction(function () use ($user, $wallet, $cost, $request, $gizmo) {

                // 1. Списываем деньги
                $wallet->decrement('balance', $cost);

                // 2. Пишем в лог транзакций
                Transaction::create([
                    'user_id' => $user->id,
                    'amount' => -$cost,
                    'type' => 'withdraw',
                    'source' => 'session_start',
                    'description' => "Оплата сеанса: ПК {$request->hostId} ({$request->minutes} мин.)",
                ]);

                // 3. Отправляем команду в Gizmo
                // ВАЖНО: у юзера должно быть поле gizmo_id, которое мы привязали при регистрации
                $isStarted = $gizmo->startSession(
                    $user->gizmo_id ?? 1, // fallback на 1 для тестов
                    $request->hostId,
                    $request->minutes
                );

                if (!$isStarted) {
                    // Генерируем ошибку, чтобы DB::transaction всё отменил!
                    throw new \Exception("Gizmo API отклонил запуск сессии");
                }
            });

            return response()->json([
                'message' => 'Сеанс запущен! Компьютер разблокирован.',
                'new_balance' => $wallet->balance
            ]);

        } catch (\Exception $e) {
            Log::error("Ошибка запуска сессии юзера {$user->id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Ошибка системы: Узел связи недоступен. Деньги возвращены на счет.'
            ], 500);
        }
    }
}
