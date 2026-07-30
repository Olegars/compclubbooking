<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
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
}
