<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Order;
use App\Models\Transaction; // Убедись, что модель импортирована
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShopController extends Controller
{
    public function getProducts()
    {
        return response()->json(Product::where('is_active', true)->get());
    }

    public function checkout(Request $request)
    {
        $user = Auth::user();
        $product = \App\Models\Product::findOrFail($request->product_id);

        if ($user->wallet->balance < $product->price) {
            return response()->json(['message' => 'Недостаточно RUB на балансе'], 400);
        }

        $user->wallet->decrement('balance', $product->price);

        // 1. Создаем транзакцию (добавили source чтобы не было ошибки SQL)
        \App\Models\Transaction::create([
            'user_id' => $user->id,
            'amount' => -$product->price,
            'description' => 'Покупка: ' . $product->name,
            'type' => 'shop',
            'source' => 'market', // ОБЯЗАТЕЛЬНОЕ ПОЛЕ
            'date' => now()->format('d.m.Y H:i'),
        ]);

        // 2. Создаем заказ (ТОЛЬКО ОДИН РАЗ)
        \App\Models\Order::create([
            'user_id' => $user->id,
            'product_name' => $product->name,
            'price' => $product->price,
            'status' => 'pending',
            'pc_name' => $request->pc_name ?? 'Dashboard'
        ]);

        return response()->json(['message' => 'Заказ оформлен! Админ скоро принесет его.']);
    }
}
