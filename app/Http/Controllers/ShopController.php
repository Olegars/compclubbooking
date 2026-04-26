<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ShopController extends Controller
{
    /**
     * Отображение главной страницы магазина для игрока
     */
    public function index()
    {
        // Берем все товары, которые есть в наличии
        $products = Product::where('stock', '>', 0)
            ->select('id', 'name', 'price', 'category', 'image', 'stock')
            ->get();

        return Inertia::render('User/Shop', [
            'products' => $products
        ]);
    }

    /**
     * Получение списка товаров через API
     */
    public function getProducts()
    {
        return response()->json(Product::all());
    }

    /**
     * Логика покупки (списание баланса и уменьшение склада)
     */
    public function checkout(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id'
        ]);

        $product = Product::findOrFail($request->product_id);
        $user = auth()->user();

        // Получаем кошелек. Если нет связи, ищем вручную по user_id
        $wallet = $user->wallet ?: DB::table('wallets')->where('user_id', $user->id)->first();

        // 1. Проверка баланса
        if (!$wallet || $wallet->balance < $product->price) {
            return response()->json(['message' => 'Недостаточно кредитов на счету'], 422);
        }

        // 2. Проверка остатка на складе
        if ($product->stock <= 0) {
            return response()->json(['message' => 'Товар закончился'], 422);
        }

        // --- ПОИСК НОМЕРА ПК (ДЛЯ POSTGRESQL) ---
        $pcName = 'Mobile';

        try {
            // А. Проверяем активную сессию в Gizmo
            $session = DB::table('gizmo_sessions')
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first();

            if ($session && !empty($session->host_name)) {
                $pcName = "ПК №" . $session->host_name;
            }
            // Б. Если сессии нет, ищем в бронях на текущий час
            else {
                $currentHour = (int)now()->format('H');

                $booking = DB::table('bookings')
                    ->where('user_id', $user->id)
                    ->where('date', now()->toDateString())
                    // Postgres требует явного приведения типов через ::int
                    ->whereRaw('start_time::int <= ?', [$currentHour])
                    ->whereRaw('(start_time::int + duration::int) > ?', [$currentHour])
                    ->first();

                if ($booking && !empty($booking->pc_ids)) {
                    $ids = is_string($booking->pc_ids) ? json_decode($booking->pc_ids, true) : $booking->pc_ids;
                    $pcNum = is_array($ids) ? $ids[0] : $ids;
                    $pcName = "ПК №" . ($pcNum ?? '??');
                }
            }
        } catch (\Exception $e) {
            Log::error("Reactor Shop: Ошибка поиска ПК: " . $e->getMessage());
            // Если поиск ПК упал, оставляем 'Mobile', чтобы покупка не прервалась
        }

        // 3. ПРОВЕДЕНИЕ СДЕЛКИ
        try {
            DB::transaction(function () use ($user, $wallet, $product, $pcName) {
                // Списываем деньги (учитываем, что $wallet может быть объектом DB или моделью)
                if ($wallet instanceof \Illuminate\Database\Eloquent\Model) {
                    $wallet->decrement('balance', $product->price);
                } else {
                    DB::table('wallets')->where('user_id', $user->id)->decrement('balance', $product->price);
                }

                // Уменьшаем склад
                $product->decrement('stock', 1);

                // Создаем заказ для админа
                Order::create([
                    'user_id'      => $user->id,
                    'product_name' => $product->name,
                    'price'        => $product->price,
                    'pc_name'      => $pcName,
                    'status'       => 'pending',
                ]);

                // Пишем в лог транзакций
                Transaction::create([
                    'user_id'     => $user->id,
                    'amount'      => -$product->price,
                    'type'        => 'purchase',
                    'source'      => 'market',
                    'description' => "Маркет: {$product->name} ({$pcName})",
                ]);
            });

            return response()->json(['message' => 'Заказ успешно оформлен!']);

        } catch (\Exception $e) {
            Log::error("Reactor Shop Error: " . $e->getMessage());
            return response()->json(['message' => 'Критический сбой: ' . $e->getMessage()], 500);
        }
    }
}
