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
            DB::transaction(function () use ($user, $amount) {
                // 1. ИСПРАВЛЕНО: Начисляем деньги на deposit_balance
                $user->wallet()->firstOrCreate([])->increment('deposit_balance', $amount);

                // 2. Записываем в историю
                Transaction::create([
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'type' => 'deposit',
                    'source' => 'system', // Потом будет 'yookassa' или 'sbp'
                    'description' => 'Пополнение депозита REACTOR',
                ]);
            });

            // ИСПРАВЛЕНО: Возвращаем новые разделенные балансы
            $user->refresh();
            return response()->json([
                'message' => 'Баланс успешно пополнен',
                'deposit_balance' => $user->wallet->deposit_balance,
                'bonus_balance' => $user->wallet->bonus_balance
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
            'hostId' => 'required|integer',
            'minutes' => 'required|integer|min:30',
            'price' => 'required|numeric|min:0',
        ]);

        $user = $request->user();
        $cost = (float) $request->price;
        $wallet = $user->wallet()->firstOrCreate([]);

        // Проверяем общую платежеспособность (Депозит + Бонусы)
        $totalAvailable = $wallet->deposit_balance + $wallet->bonus_balance;

        if ($totalAvailable < $cost) {
            return response()->json([
                'message' => 'Недостаточно средств. Ваш баланс: ' . $totalAvailable . '₽'
            ], 402);
        }

        try {
            DB::transaction(function () use ($user, $wallet, $cost, $request, $gizmo) {

                // 1. УМНОЕ СПИСАНИЕ (Сначала жжем бонусы)
                $payFromBonus = min($wallet->bonus_balance, $cost);
                $payFromDeposit = $cost - $payFromBonus;

                if ($payFromBonus > 0) {
                    $wallet->decrement('bonus_balance', $payFromBonus);
                    Transaction::create([
                        'user_id' => $user->id,
                        'amount' => -$payFromBonus,
                        'type' => 'withdraw',
                        'source' => 'bonus_account',
                        'description' => "Оплата бонусами: ПК {$request->hostId} ({$request->minutes} мин.)",
                    ]);
                }

                if ($payFromDeposit > 0) {
                    $wallet->decrement('deposit_balance', $payFromDeposit);
                    $wallet->increment('total_spent', $payFromDeposit); // Растим статус лояльности

                    Transaction::create([
                        'user_id' => $user->id,
                        'amount' => -$payFromDeposit,
                        'type' => 'withdraw',
                        'source' => 'deposit_account',
                        'description' => "Оплата с депозита: ПК {$request->hostId} ({$request->minutes} мин.)",
                    ]);

                    // 2. АВТОМАТИЧЕСКИЙ КЕШБЭК (Начисляем только с реальных трат)
                    // Допустим, базовый кешбэк клуба - 5%
                    $cashbackPercent = 0.05;
                    $cashbackAmount = $payFromDeposit * $cashbackPercent;

                    if ($cashbackAmount > 0) {
                        $wallet->increment('bonus_balance', $cashbackAmount);
                        Transaction::create([
                            'user_id' => $user->id,
                            'amount' => $cashbackAmount,
                            'type' => 'deposit',
                            'source' => 'cashback',
                            'description' => "Кешбэк за сеанс",
                        ]);
                    }
                }

                // 3. Отправка команды в Gizmo
                $isStarted = $gizmo->startSession(
                    $user->gizmo_id ?? 1,
                    $request->hostId,
                    $request->minutes
                );

                if (!$isStarted) {
                    throw new \Exception("Gizmo API отклонил запуск сессии");
                }
            });

            // Возвращаем свежие разделенные балансы для интерфейса
            $wallet->refresh();
            return response()->json([
                'message' => 'Сеанс запущен!',
                'deposit_balance' => $wallet->deposit_balance,
                'bonus_balance' => $wallet->bonus_balance
            ]);

        } catch (\Exception $e) {
            Log::error("Ошибка запуска сессии юзера {$user->id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Ошибка системы. Деньги не списаны.'
            ], 500);
        }
    }
}
