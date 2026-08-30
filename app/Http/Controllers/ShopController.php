<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PreSessionOrderService;
use App\Services\ProductStockService;
use App\Support\OrderDeliveryTarget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ShopController extends Controller
{
    /**
     * Активные заказы текущего пользователя (для индикатора «в работе»).
     */
    public function activeOrders(Request $request)
    {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['orders' => []]);
        }

        $orders = Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'cooking', Order::STATUS_SCHEDULED])
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(function (Order $order) {
                $items = Order::normalizeItems(
                    $order->items,
                    $order->product_name,
                    (float) $order->price
                );
                $labels = [
                    'pending' => 'Заказ принят',
                    'cooking' => 'Заказ в работе',
                    Order::STATUS_SCHEDULED => 'Доставим к началу сессии',
                ];

                return [
                    'id' => $order->id,
                    'status' => $order->status,
                    'status_label' => $labels[$order->status] ?? 'Заказ в работе',
                    'product_name' => $order->product_name,
                    'items' => $items,
                    'price' => (float) $order->price,
                    'pc_name' => $order->pc_name,
                ];
            })
            ->values();

        return response()->json([
            'orders' => $orders,
            'has_active_order' => $orders->isNotEmpty(),
        ]);
    }

    /**
     * Отображение главной страницы магазина для витрины киоска / игрока
     */
    public function index()
    {
        $products = Product::select('id', 'name', 'price', 'category', 'image', 'stock')
            ->where('stock', '>', 0)
            ->orderBy('name')
            ->get();

        // Рендерим вьюшку магазина
        $delivery = auth()->user()
            ? app(PreSessionOrderService::class)->deliveryContextForUser((int) auth()->id())
            : null;

        return Inertia::render('User/Shop', [
            'products' => $products,
            'delivery' => $delivery,
        ]);
    }

    public function deliveryContext()
    {
        $user = auth()->user();
        if (! $user) {
            return response()->json([
                'mode' => 'none',
                'message' => 'Чтобы заказать доставку к ПК, войдите в аккаунт',
                'pc_name' => null,
                'immediate' => false,
            ]);
        }

        return response()->json(
            app(PreSessionOrderService::class)->deliveryContextForUser((int) $user->id)
        );
    }

    /**
     * Получение списка товаров для витрины киоска (через API)
     */
    public function getProducts(Request $request)
    {
        $terminalId = (int) $request->input('terminal_id', 0);

        // 1. Ищем, есть ли активные заказы для этого ПК
        $hasActiveOrder = false;
        $statusText = '';

        if ($terminalId > 0) {
            $order = DB::table('orders')
                ->whereIn('pc_name', OrderDeliveryTarget::matchLabels($terminalId))
                ->whereIn('status', ['pending', 'cooking'])
                ->orderByDesc('id')
                ->first();

            $hasActiveOrder = (bool) $order;
            $labels = [
                'pending' => 'ЗАКАЗ ПРИНЯТ',
                'cooking' => 'В РАБОТЕ',
                'delivered' => 'ЗАКАЗ ВЫПОЛНЕН',
                'cancelled' => 'ЗАКАЗ ОТМЕНЁН',
            ];
            $statusText = $order ? ($labels[$order->status] ?? 'В РАБОТЕ') : '';
        }

        // 2. Берем сам список товаров
        $products = Product::select('id', 'name', 'price', 'category', 'image', 'stock')
            ->where('stock', '>', 0)
            ->orderBy('name')
            ->get();

        // 3. Отдаем объединенный объект
        $delivery = null;
        if (auth()->user()) {
            $delivery = app(PreSessionOrderService::class)->deliveryContextForUser((int) auth()->id());
        }

        return response()->json([
            'has_active_order' => $hasActiveOrder,
            'status_text'      => $statusText,
            'products'         => $products,
            'delivery'         => $delivery,
        ]);
    }

    /**
     * Обработка заказа (сайт/кабинет, шелл через web-API, киоск/терминал)
     * Multi-item: { items: [{ product_id, qty }] }
     * Legacy: { product_id } (+ optional qty)
     */
    public function checkout(Request $request)
    {
        $request->validate([
            'product_id'       => 'nullable|exists:products,id',
            'qty'              => 'nullable|integer|min:1|max:50',
            'items'            => 'nullable|array|min:1',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.qty'      => 'nullable|integer|min:1|max:50',
            'order_type'       => 'required|in:desktop,kiosk',
            'payment_method'   => 'required|in:account,sbp_qr,card',
            'client_phone'     => 'nullable|string',
            'terminal_id'      => 'nullable|integer',
            'computer_id'      => 'nullable|integer',
        ]);

        $rawItems = $request->input('items');
        if (! is_array($rawItems) || count($rawItems) === 0) {
            if (! $request->filled('product_id')) {
                return response()->json(['message' => 'Корзина пуста'], 422);
            }
            $rawItems = [[
                'product_id' => (int) $request->product_id,
                'qty' => max(1, (int) $request->input('qty', 1)),
            ]];
        }

        $qtyByProduct = [];
        foreach ($rawItems as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            $qty = max(1, (int) ($row['qty'] ?? 1));
            if ($pid <= 0) {
                continue;
            }
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + $qty;
        }

        if ($qtyByProduct === []) {
            return response()->json(['message' => 'Корзина пуста'], 422);
        }

        $orderType = $request->input('order_type');
        $paymentMethod = $request->input('payment_method');
        $stockService = app(ProductStockService::class);

        $products = Product::whereIn('id', array_keys($qtyByProduct))->get()->keyBy('id');
        if ($products->count() !== count($qtyByProduct)) {
            return response()->json(['message' => 'Товар не найден'], 404);
        }

        $lineItems = [];
        $totalPrice = 0.0;
        foreach ($qtyByProduct as $pid => $qty) {
            /** @var Product $product */
            $product = $products[$pid];
            try {
                $stockService->assertAvailable($product, $qty);
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage() ?: 'Недостаточно товара на складе'], 422);
            }
            $unit = (float) $product->price;
            $lineTotal = $unit * $qty;
            $totalPrice += $lineTotal;
            $lineItems[] = [
                'product_id' => (int) $product->id,
                'name' => $product->name,
                'qty' => $qty,
                'unit_price' => $unit,
                'line_total' => $lineTotal,
            ];
        }

        $user = null;

        if ($paymentMethod === 'account') {
            if ($orderType === 'kiosk') {
                if (! $request->filled('client_phone')) {
                    return response()->json(['message' => 'Введите номер телефона для оплаты с баланса'], 422);
                }

                $cleanPhone = preg_replace('/[^0-9]/', '', (string) $request->client_phone);
                $local = strlen($cleanPhone) >= 10 ? substr($cleanPhone, -10) : $cleanPhone;
                $user = User::query()
                    ->where(function ($q) use ($cleanPhone, $local) {
                        $q->where('phone', $cleanPhone)
                            ->orWhere('phone', '+'.$cleanPhone)
                            ->orWhere('phone', '7'.$local)
                            ->orWhere('phone', '+7'.$local)
                            ->orWhere('phone', '8'.$local);
                    })
                    ->first();
            } else {
                $user = auth()->user();
            }

            if (! $user) {
                return response()->json(['message' => 'Пользователь с таким номером телефона не найден в клубе'], 422);
            }

            $balance = method_exists($user, 'syncBalanceToWallet')
                ? $user->syncBalanceToWallet()
                : (float) ($user->wallet?->balance ?? 0);
            if ($balance < $totalPrice) {
                return response()->json(['message' => 'Недостаточно средств на клубном балансе'], 422);
            }
        }

        $preSession = app(PreSessionOrderService::class);
        $resolved = null;
        $pcName = null;

        if ($orderType === 'kiosk') {
            $terminalId = (int) $request->input('computer_id', $request->input('terminal_id', 0));
            if ($terminalId < 1) {
                return response()->json([
                    'message' => 'Не указан терминал для доставки заказа',
                ], 422);
            }
            $resolved = $preSession->resolveForComputer($terminalId);
            if ($resolved['mode'] === 'none') {
                $pcName = OrderDeliveryTarget::labelForComputerId($terminalId);
                $resolved = [
                    'mode' => 'session',
                    'message' => "Заказ оформлен! Доставим к {$pcName}.",
                    'pc_name' => $pcName,
                    'booking' => null,
                    'immediate' => true,
                    'fulfill_at' => null,
                    'session_starts_at' => null,
                ];
            } else {
                $pcName = $resolved['pc_name'] ?: OrderDeliveryTarget::labelForComputerId($terminalId);
            }
        } else {
            if (! $user) {
                $user = auth()->user();
            }
            if (! $user) {
                return response()->json([
                    'message' => 'Чтобы заказать доставку к ПК, войдите в аккаунт',
                ], 422);
            }

            $resolved = $preSession->resolveForUser((int) $user->id);
            if ($resolved['mode'] === 'none' || ! $resolved['pc_name']) {
                return response()->json([
                    'message' => $resolved['message'] ?: 'Нет активной сессии в клубе. Заказ можно оформить только когда вы сидите за ПК',
                ], 422);
            }

            $pcName = $resolved['pc_name'];

            if ($request->filled('terminal_id') && (int) $request->terminal_id > 0 && $resolved['mode'] === 'session') {
                $booking = $resolved['booking'];
                $sessionPc = $booking ? OrderDeliveryTarget::computerIdFromBooking($booking) : null;
                if ($sessionPc && (int) $request->terminal_id !== (int) $sessionPc) {
                    return response()->json([
                        'message' => 'Заказ можно оформить только с ПК активной сессии',
                    ], 422);
                }
            }
        }

        $summary = Order::summaryFromItems($lineItems);
        $orderAttrs = $preSession->orderCreateAttributes($resolved, $pcName);
        $scheduled = ($orderAttrs['status'] ?? '') === Order::STATUS_SCHEDULED;
        $createdOrder = null;

        try {
            DB::transaction(function () use (
                $user,
                $lineItems,
                $summary,
                $totalPrice,
                $paymentMethod,
                $stockService,
                $products,
                $qtyByProduct,
                $orderAttrs,
                &$createdOrder
            ) {
                if ($paymentMethod === 'account' && $user) {
                    $user->syncBalanceToWallet();
                    $wallet = $user->wallet()->first();
                    if (! $wallet) {
                        throw new \RuntimeException('Кошелёк пользователя не найден');
                    }
                    $wallet->debitSpendable((float) $totalPrice);

                    Transaction::create([
                        'user_id'     => $user->id,
                        'amount'      => -$totalPrice,
                        'type'        => 'purchase',
                        'source'      => 'market',
                        'description' => 'Магазин: '.$summary.' (Списание с баланса)',
                    ]);
                }

                $createdOrder = Order::create(array_merge($orderAttrs, [
                    'user_id'      => $user ? $user->id : null,
                    'product_name' => $summary,
                    'items'        => $lineItems,
                    'price'        => $totalPrice,
                ]));

                foreach ($qtyByProduct as $pid => $qty) {
                    $stockService->decrementUnmarked($products[$pid], $qty, (int) $createdOrder->id);
                }

                $stockService->reserveMarkedForOrder((int) $createdOrder->id, $lineItems);
            });

            if ($createdOrder) {
                $preSession->enqueueKitchenIfPending($createdOrder);
            }

            $successMessage = $scheduled
                ? PreSessionOrderService::BOOKING_ORDER_MESSAGE
                : "Заказ оформлен! Доставим к {$pcName}.";

            return response()->json([
                'status' => 'success',
                'message' => $successMessage,
                'pc_name' => $pcName,
                'scheduled' => $scheduled,
                'order_id' => $createdOrder?->id,
                'order_status' => $createdOrder?->status,
            ]);

        } catch (\Exception $e) {
            Log::error("Reactor Shop Error: " . $e->getMessage());
            return response()->json(['message' => 'Ошибка сервера при проведении платежа: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Киоск клуба после брони: телефон только что подтверждён SMS, доставка к забронированному ПК.
     */
    public function terminalCheckout(Request $request)
    {
        $request->merge([
            'order_type' => 'kiosk',
            'payment_method' => $request->input('payment_method', 'account'),
            'terminal_id' => $request->input('computer_id', $request->input('terminal_id')),
        ]);

        return $this->checkout($request);
    }
}
