<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShopController extends Controller
{
    /**
     * Отображение главной страницы магазина для игрока
     */
    public function index()
    {
        // Берем все товары, которые есть в наличии (stock > 0)
        $products = Product::where('stock', '>', 0)
            ->select('id', 'name', 'price', 'category', 'image', 'stock')
            ->get();

        return Inertia::render('User/Shop', [
            'products' => $products
        ]);
    }

    /**
     * Получение списка товаров через API (для динамического обновления)
     */
    public function getProducts()
    {
        return response()->json(Product::where('stock', '>', 0)->get());
    }

    /**
     * Логика покупки (списание баланса и уменьшение склада)
     */
    public function checkout(Request $request)
    {
        // Здесь твоя логика оформления заказа, которую мы писали ранее
    }
}
