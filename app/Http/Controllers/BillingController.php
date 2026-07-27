<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\GizmoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    /**
     * Пополнение баланса (заглушка оплаты → credit deposit_balance).
     * В будущем здесь будет вызов API эквайринга (ЮKassa / СБП).
     */
    public function topUp(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:100',
            'method' => 'nullable|string|in:card,sbp,system',
        ]);

        $user = $request->user();
        $amount = (float) $request->amount;
        $source = $request->input('method', 'system');

        try {
            $newBalance = 0.0;
            $bonusBalance = 0.0;

            DB::transaction(function () use ($user, $amount, $source, &$newBalance, &$bonusBalance) {
                // Payment stub: treat request as successful charge, then credit wallet.
                $user->syncBalanceToWallet();
                $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id]);
                $newBalance = $wallet->creditSpendable($amount);

                Transaction::create([
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'type' => 'deposit',
                    'source' => $source ?: 'system',
                    'description' => 'Пополнение депозита REACTOR',
                ]);

                $wallet->refresh();
                $bonusBalance = (float) ($wallet->getAttributes()['bonus_balance'] ?? 0);
            });

            return response()->json([
                'message' => 'Баланс успешно пополнен',
                'new_balance' => $newBalance,
                'deposit_balance' => $newBalance,
                'bonus_balance' => $bonusBalance,
                'balance' => $newBalance,
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
        $user->syncBalanceToWallet();
        $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id]);

        $deposit = $wallet->depositAmount();
        $bonus = (float) ($wallet->getAttributes()['bonus_balance'] ?? 0);
        $totalAvailable = $deposit + $bonus;

        if ($totalAvailable < $cost) {
            return response()->json([
                'message' => 'Недостаточно средств. Ваш баланс: ' . $totalAvailable . '₽'
            ], 402);
        }

        try {
            DB::transaction(function () use ($user, $wallet, $cost, $request, $gizmo, $bonus) {

                // 1. УМНОЕ СПИСАНИЕ (Сначала жжем бонусы)
                $payFromBonus = min($bonus, $cost);
                $payFromDeposit = $cost - $payFromBonus;

                if ($payFromBonus > 0) {
                    DB::table('wallets')->where('id', $wallet->id)->decrement('bonus_balance', $payFromBonus);
                    Transaction::create([
                        'user_id' => $user->id,
                        'amount' => -$payFromBonus,
                        'type' => 'withdraw',
                        'source' => 'bonus_account',
                        'description' => "Оплата бонусами: ПК {$request->hostId} ({$request->minutes} мин.)",
                    ]);
                }

                if ($payFromDeposit > 0) {
                    $wallet->debitSpendable($payFromDeposit);
                    DB::table('wallets')->where('id', $wallet->id)->increment('total_spent', $payFromDeposit);

                    Transaction::create([
                        'user_id' => $user->id,
                        'amount' => -$payFromDeposit,
                        'type' => 'withdraw',
                        'source' => 'deposit_account',
                        'description' => "Оплата с депозита: ПК {$request->hostId} ({$request->minutes} мин.)",
                    ]);

                    $cashbackPercent = 0.05;
                    $cashbackAmount = $payFromDeposit * $cashbackPercent;

                    if ($cashbackAmount > 0) {
                        DB::table('wallets')->where('id', $wallet->id)->increment('bonus_balance', $cashbackAmount);
                        Transaction::create([
                            'user_id' => $user->id,
                            'amount' => $cashbackAmount,
                            'type' => 'deposit',
                            'source' => 'cashback',
                            'description' => "Кешбэк за сеанс",
                        ]);
                    }
                }

                $isStarted = $gizmo->startSession(
                    $user->gizmo_id ?? 1,
                    $request->hostId,
                    $request->minutes
                );

                if (!$isStarted) {
                    throw new \Exception("Gizmo API отклонил запуск сессии");
                }
            });

            $wallet->refresh();
            $newDeposit = $wallet->depositAmount();
            $newBonus = (float) ($wallet->getAttributes()['bonus_balance'] ?? 0);

            return response()->json([
                'message' => 'Сеанс запущен!',
                'deposit_balance' => $newDeposit,
                'bonus_balance' => $newBonus,
                'new_balance' => $newDeposit,
                'balance' => $newDeposit,
            ]);

        } catch (\Exception $e) {
            Log::error("Ошибка запуска сессии юзера {$user->id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Ошибка системы. Деньги не списаны.'
            ], 500);
        }
    }
}
