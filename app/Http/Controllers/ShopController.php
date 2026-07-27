<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ShopController extends Controller
{
    /**
     * Отображение главной страницы магазина для витрины киоска / игрока
     */
    public function index()
    {
        // Берем все товары, которые физически есть в наличии на складе
        $products = Product::where('stock', '>', 0)
            ->select('id', 'name', 'price', 'category', 'image', 'stock')
            ->get();

        // Рендерим вьюшку магазина
        return Inertia::render('User/Shop', [
            'products' => $products
        ]);
    }

    /**
     * Получение списка товаров для витрины киоска (через API)
     */
    public function getProducts(Request $request)
    {
        $terminalId = $request->input('terminal_id', 0);

        // 1. Ищем, есть ли активные заказы для этого ПК
        $hasActiveOrder = false;
        $statusText = '';

        if ($terminalId > 0) {
            $hasActiveOrder = DB::table('orders')
                ->where('pc_name', 'ПК №' . $terminalId)
                ->where('status', 'pending')
                ->exists();

            $statusText = $hasActiveOrder ? 'Заказ в работе' : '';
        }

        // 2. Берем сам список товаров
        $products = Product::select('id', 'name', 'price', 'category', 'image', 'stock')->get();

        // 3. Отдаем объединенный объект
        return response()->json([
            'has_active_order' => $hasActiveOrder,
            'status_text'      => $statusText,
            'products'         => $products
        ]);
    }

    /**
     * Обработка заказа (поддерживает и ПК из зала, и Киоск самообслуживания)
     */
    public function checkout(Request $request)
    {
        // 1. Валидация входящего пакета от фронтенда
        $request->validate([
            'product_id'     => 'required|exists:products,id',
            'order_type'     => 'required|in:desktop,kiosk', // desktop = из зала с ПК, kiosk = со стойки
            'payment_method' => 'required|in:account,sbp_qr,card', // Способ оплаты
            'client_phone'   => 'nullable|string', // Телефон (нужен только для списания баланса на киоске)
            'terminal_id'    => 'nullable|integer', // ID ПК (если заказ из зала)
        ]);

        $product = Product::findOrFail($request->product_id);
        $orderType = $request->input('order_type');
        $paymentMethod = $request->input('payment_method');

        // 2. Базовая проверка склада
        if ($product->stock <= 0) {
            return response()->json(['message' => 'Извините, этот товар только что закончился'], 422);
        }

        $user = null;

        // 3. Логика авторизации и проверки кошелька при оплате с баланса клуба
        if ($paymentMethod === 'account') {
            // Если заказ идет с киоска, ищем пользователя по введенному номеру телефона
            if ($orderType === 'kiosk') {
                if (!$request->filled('client_phone')) {
                    return response()->json(['message' => 'Введите номер телефона для оплаты с баланса'], 422);
                }

                // Очищаем номер от маски (оставляем только цифры)
                $cleanPhone = preg_replace('/[^0-9]/', '', $request->client_phone);
                $user = User::where('phone', $cleanPhone)->first();
            } else {
                // Если заказ стандартный (из игрового шелла ПК), берем текущую сессию
                $user = auth()->user();
            }

            if (!$user) {
                return response()->json(['message' => 'Пользователь с таким номером телефона не найден в клубе'], 422);
            }

            // Проверяем состояние счета (wallet deposit / legacy balance)
            $balance = method_exists($user, 'syncBalanceToWallet')
                ? $user->syncBalanceToWallet()
                : (float) ($user->wallet?->balance ?? 0);
            if ($balance < $product->price) {
                return response()->json(['message' => 'Недостаточно средств на клубном балансе'], 422);
            }
        }

        // --- ФОРМИРОВАНИЕ ТОЧКИ ДОСТАВКИ (ДЛЯ АДМИНИСТРАТОРА) ---
        $pcName = 'Стойка самообслуживания'; // Дефолт для режима Киоска

        if ($orderType === 'desktop') {
            if ($request->has('terminal_id') && $request->terminal_id > 0) {
                $pcName = "ПК №" . $request->terminal_id;
            } elseif ($user) {
                // Резервный поиск сессии в Gizmo
                $session = DB::table('gizmo_sessions')->where('user_id', $user->id)->where('is_active', true)->first();
                if ($session && !empty($session->host_name)) {
                    $pcName = "ПК №" . $session->host_name;
                }
            }
        }

        // 4. АТОМАРНАЯ ТРАНЗАКЦИЯ ПРОВЕДЕНИЯ ЗАКАЗА
        try {
            DB::transaction(function () use ($user, $product, $pcName, $paymentMethod) {

                // Списываем деньги с баланса аккаунта (если применимо)
                if ($paymentMethod === 'account' && $user) {
                    $walletRow = DB::table('wallets')->where('user_id', $user->id)->first();
                    if ($walletRow && property_exists($walletRow, 'deposit_balance')) {
                        DB::table('wallets')->where('user_id', $user->id)->decrement('deposit_balance', $product->price);
                    } else {
                        DB::table('wallets')->where('user_id', $user->id)->decrement('balance', $product->price);
                    }

                    // Логируем списание в историю транзакций профиля
                    Transaction::create([
                        'user_id'     => $user->id,
                        'amount'      => -$product->price,
                        'type'        => 'purchase',
                        'source'      => 'market',
                        'description' => "Киоск: {$product->name} (Списание с баланса)",
                    ]);
                }

                // Уменьшаем остаток на складе
                $product->decrement('stock', 1);

                // Создаем заказ со статусом 'pending' для отображения на панели админа
                Order::create([
                    'user_id'      => $user ? $user->id : null, // Для внешних оплат картой/QR гость анонимен
                    'product_name' => $product->name,
                    'price'        => $product->price,
                    'pc_name'      => $pcName, // Админ на баре увидит: "Стойка самообслуживания"
                    'status'       => 'pending',
                ]);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Заказ успешно оплачен! Заберите товар у стойки администратора.'
            ]);

        } catch (\Exception $e) {
            Log::error("Reactor Kiosk Error: " . $e->getMessage());
            return response()->json(['message' => 'Ошибка сервера при проведении платежа: ' . $e->getMessage()], 500);
        }
    }
}
