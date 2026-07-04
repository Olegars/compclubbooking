<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PromoCodeController extends Controller
{
    public function apply(Request $request)
    {
        // 1. Валидация ввода
        $request->validate([
            'code' => 'required|string|max:50',
        ]);

        $user = $request->user();
        // Ищем код без учета регистра (чтобы summer2026 и SUMMER2026 работали одинаково)
        $promo = PromoCode::where('code', strtoupper($request->code))->first();

        // 2. Блок проверок (Guard clauses)
        if (!$promo) {
            return response()->json(['message' => 'Промокод не найден'], 404);
        }

        if ($promo->used_count >= $promo->max_uses) {
            return response()->json(['message' => 'Лимит активаций этого промокода исчерпан'], 400);
        }

        // Защита от повторного использования: проверяем, нет ли юзера в сводной таблице
        if ($promo->users()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'Вы уже активировали этот промокод'], 400);
        }

        // 3. Выдача награды (Обернуто в транзакцию для защиты от Race Condition)
        try {
            DB::transaction(function () use ($user, $promo) {

                // Фиксируем использование кода конкретным юзером
                $promo->users()->attach($user->id);
                $promo->increment('used_count');

                $wallet = $user->wallet()->firstOrCreate([]);

                // Логика начисления в зависимости от типа кода
                if ($promo->type === 'bonus_money') {
                    // Начисляем ТОЛЬКО на бонусный счет (фантики)
                    $wallet->increment('bonus_balance', $promo->value);

                    // Пишем в лог транзакций
                    Transaction::create([
                        'user_id' => $user->id,
                        'amount' => $promo->value,
                        'type' => 'deposit',
                        'source' => 'promo_code',
                        'description' => "Активация промокода: {$promo->code}",
                    ]);
                }
                // В будущем здесь можно добавить логику для type === 'discount'
            });

            // Возвращаем успех и новые балансы, чтобы шелл сразу их обновил на экране
            $user->refresh();
            return response()->json([
                'message' => 'Промокод успешно применен! Бонусы зачислены.',
                'deposit_balance' => $user->wallet->deposit_balance,
                'bonus_balance' => $user->wallet->bonus_balance
            ]);

        } catch (\Exception $e) {
            Log::error("Ошибка активации промокода юзером {$user->id}: " . $e->getMessage());
            return response()->json(['message' => 'Внутренняя ошибка сервера'], 500);
        }
    }
}
