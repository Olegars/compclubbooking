<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ComputerSosAlert;
use App\Models\ComputerInputAlert;
use App\Support\AdminAlerts;
use App\Services\ProductStockService;
// Если у тебя есть модель BonusLog, раскомментируй:
// use App\Models\BonusLog;

class AdminController extends Controller
{
    // ... твои старые методы (index, orders и т.д.) оставляем ...

    // ==========================================
    // 1. ДАШБОАРД И КОМПЕНСАЦИИ
    // ==========================================
    public function dashboard()
    {
        // Получаем ID первого клуба (или текущего активного)
        $clubId = DB::table('clubs')->first()->id ?? 1;

        $computers = app(\App\Services\ComputerPowerService::class)->statusSnapshot((int) $clubId);
        $fanOrphans = app(\App\Services\Fan\FanControlService::class)->orphanSnapshot((int) $clubId);

        return Inertia::render('Admin/Dashboard', [
            'computers' => $computers,
            'fanOrphans' => $fanOrphans,
            'stats' => [
                'TOTAL_REVENUE' => DB::table('orders')->where('status', 'delivered')->sum('price') ?? 0,
                'ACTIVE_SESSIONS' => $computers->where('status', 'busy')->count(),
                'NEW_USERS_TODAY' => DB::table('users')->whereDate('created_at', today())->count()
            ]
        ]);
    }

    public function searchUser(Request $request)
    {
        $request->validate(['phone' => 'required|string']);

        $user = User::with('wallet')->where('phone', 'like', '%' . $request->phone . '%')->first();

        if (!$user) {
            return response()->json(['message' => 'Гость не найден'], 404);
        }

        $balance = $user->availableBalance();

        return response()->json(array_merge($user->toArray(), [
            'balance' => $balance,
            'total_balance' => $balance,
        ]));
    }

    public function giveBonus(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'minutes' => 'required|integer|min:1',
            'reason'  => 'required|string|max:255'
        ]);

        // Логируем выдачу бонуса в БД (таблица, которую мы создали в миграции)
        DB::table('bonus_logs')->insert([
            'user_id'    => $request->user_id,
            'admin_id'   => Auth::guard('admin')->id(), // ID текущего оператора
            'minutes'    => $request->minutes,
            'reason'     => $request->reason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Бонусное время залогировано; фактическое начисление — через бронирование/админку

        return response()->json(['message' => 'Бонус успешно начислен и залогирован']);
    }

    /**
     * Касса: ручное пополнение deposit_balance (без эквайринга).
     */
    public function topUpBalance(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:100',
            'reason' => 'nullable|string|max:255',
        ]);

        $user = User::findOrFail($request->user_id);
        $amount = (float) $request->amount;

        $newBalance = DB::transaction(function () use ($user, $amount, $request) {
            $user->syncBalanceToWallet();
            $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id]);
            $balance = $wallet->creditSpendable($amount);

            \App\Models\Transaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => 'deposit',
                'source' => 'admin_cash',
                'description' => $request->reason ?: ('Пополнение кассой '.\App\Support\ClubBrand::name()),
            ]);

            return $balance;
        });

        return response()->json([
            'message' => 'Баланс пополнен',
            'balance' => $newBalance,
            'deposit_balance' => $newBalance,
            'new_balance' => $newBalance,
        ]);
    }

    // ==========================================
    // 2. СКЛАД МАРКЕТА (ИНВЕНТАРЬ)
    // ==========================================
    public function inventory()
    {
        $role = auth('admin')->user()?->role;
        $canManageCatalog = in_array($role, ['supervisor', 'owner'], true);

        return Inertia::render('Admin/Inventory', [
            'canManageCatalog' => $canManageCatalog,
            'canAdjustStock' => true,
            'reasonCodes' => collect(ProductStockService::WRITE_OFF_REASON_CODES)
                ->map(fn ($code) => [
                    'code' => $code,
                    'label' => \App\Models\StockMovement::REASON_LABELS[$code] ?? $code,
                ])
                ->values()
                ->all(),
            'suppliers' => \App\Models\Supplier::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'products' => Product::query()
                ->orderBy('name')
                ->get([
                    'id', 'name', 'category', 'price', 'cost_price', 'stock', 'min_stock',
                    'barcode', 'image', 'requires_marking', 'supplier_id',
                ]),
        ]);
    }

    public function listInventoryProducts()
    {
        return response()->json(
            Product::query()
                ->orderBy('name')
                ->get([
                    'id', 'name', 'category', 'price', 'cost_price', 'stock', 'min_stock',
                    'barcode', 'image', 'requires_marking', 'supplier_id',
                ])
        );
    }

    public function saveProduct(Request $request)
    {
        // 1. Валидируем данные. image здесь — это загружаемый файл изображения
        $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'category' => 'required|string',
            'price' => 'required|numeric',
            'cost_price' => 'nullable|numeric|min:0',
            'stock' => 'nullable|integer|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'barcode' => 'nullable|string|max:64',
            'requires_marking' => 'nullable|boolean',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        $requiresMarking = filter_var($request->input('requires_marking', false), FILTER_VALIDATE_BOOLEAN);

        $data = [
            'name' => $request->name,
            'category' => $request->category,
            'price' => $request->price,
            'barcode' => $request->barcode ?: null,
            'requires_marking' => $requiresMarking,
            'supplier_id' => $request->input('supplier_id') !== null && $request->input('supplier_id') !== ''
                ? (int) $request->supplier_id
                : null,
            'min_stock' => $request->input('min_stock') !== null && $request->input('min_stock') !== ''
                ? (int) $request->min_stock
                : null,
        ];

        if ($request->has('cost_price')) {
            $rawCost = $request->input('cost_price');
            $data['cost_price'] = ($rawCost === null || $rawCost === '')
                ? null
                : round((float) $rawCost, 2);
        }

        // Stock for marked products is derived from units — only allow manual stock for unmarked
        if (! $requiresMarking) {
            $data['stock'] = $request->stock ?? 0;
        }

        // 2. ОБРАБОТКА И ЗАГРУЗКА ФАЙЛА КАРТИНКИ
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = time() . '_' . $file->getClientOriginalName();

            $targetPath = public_path('images/shop');

            if (! file_exists($targetPath)) {
                mkdir($targetPath, 0755, true);
            }

            $file->move($targetPath, $filename);
            $data['image'] = 'images/shop/' . $filename;
        }

        if ($request->id) {
            DB::table('products')->where('id', $request->id)->update($data);
            $product = Product::find($request->id);
            if ($product && $product->requires_marking) {
                app(ProductStockService::class)->syncMarkedStock($product);
            }
        } else {
            if (! isset($data['image'])) {
                $data['image'] = '';
            }
            if ($requiresMarking) {
                $data['stock'] = 0;
            }
            DB::table('products')->insert($data);
        }

        return response()->json(['message' => 'Товар успешно сохранен']);
    }

    public function deleteProduct($id)
    {
        DB::table('products')->where('id', $id)->delete();

        return response()->json(['message' => 'Товар удален']);
    }

    /**
     * Scan receive: DataMatrix for marked SKU, or EAN +1 for unmarked.
     */
    public function receiveScan(Request $request, ProductStockService $stock)
    {
        $request->validate([
            'code' => 'required|string|max:512',
            'product_id' => 'nullable|integer|exists:products,id',
            'unit_cost' => 'nullable|numeric|min:0',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'invoice_number' => 'nullable|string|max:64',
            'create_invoice' => 'nullable|boolean',
        ]);

        $admin = auth('admin')->user();
        $code = ProductUnit::normalizeCode($request->code);
        $unitCost = $request->filled('unit_cost') ? (float) $request->unit_cost : null;
        $supplierId = $request->filled('supplier_id') ? (int) $request->supplier_id : null;
        $createInvoice = filter_var($request->input('create_invoice', true), FILTER_VALIDATE_BOOLEAN);
        $invoiceNumber = $request->input('invoice_number');

        try {
            // Explicit product (receive mode)
            if ($request->filled('product_id')) {
                $product = Product::findOrFail($request->product_id);
                if ($product->requires_marking) {
                    $unit = $stock->receiveByMarkingCode(
                        $product,
                        $code,
                        (int) $admin->id,
                        $unitCost,
                        $supplierId,
                        $createInvoice,
                        $invoiceNumber,
                    );

                    return response()->json([
                        'status' => 'received',
                        'mode' => 'marking',
                        'product' => $product->fresh(),
                        'unit_id' => $unit->id,
                        'new_stock' => (int) $product->fresh()->stock,
                    ]);
                }

                $fresh = $stock->receiveUnmarked(
                    $product,
                    1,
                    (int) $admin->id,
                    $unitCost,
                    $supplierId,
                    $createInvoice,
                    $invoiceNumber,
                );

                return response()->json([
                    'status' => 'received',
                    'mode' => 'quantity',
                    'product' => $fresh,
                    'new_stock' => (int) $fresh->stock,
                ]);
            }

            // Auto: try GTIN → marked product, else EAN catalog barcode
            $gtin = ProductUnit::extractGtin($code);
            $product = null;

            if ($gtin) {
                $product = Product::query()
                    ->where('requires_marking', true)
                    ->where(function ($q) use ($gtin, $code) {
                        $q->where('barcode', $gtin)
                            ->orWhere('barcode', ltrim($gtin, '0'))
                            ->orWhere('barcode', $code);
                    })
                    ->first();
            }

            if (! $product) {
                $product = Product::query()->where('barcode', $code)->first();
            }

            if (! $product) {
                return response()->json([
                    'message' => 'Товар не опознан. Укажите позицию или заведите GTIN/штрихкод в карточке.',
                ], 404);
            }

            if ($product->requires_marking) {
                // Full DataMatrix required (not just EAN)
                if (mb_strlen($code) < 20 && ! str_starts_with($code, '01')) {
                    return response()->json([
                        'message' => 'Для маркированной позиции нужен полный DataMatrix (КМ), не только EAN.',
                        'product_id' => $product->id,
                    ], 422);
                }

                $unit = $stock->receiveByMarkingCode(
                    $product,
                    $code,
                    (int) $admin->id,
                    $unitCost,
                    $supplierId,
                    $createInvoice,
                    $invoiceNumber,
                );

                return response()->json([
                    'status' => 'received',
                    'mode' => 'marking',
                    'product' => $product->fresh(),
                    'unit_id' => $unit->id,
                    'new_stock' => (int) $product->fresh()->stock,
                ]);
            }

            $fresh = $stock->receiveUnmarked(
                $product,
                1,
                (int) $admin->id,
                $unitCost,
                $supplierId,
                $createInvoice,
                $invoiceNumber,
            );

            return response()->json([
                'status' => 'received',
                'mode' => 'quantity',
                'product' => $fresh,
                'new_stock' => (int) $fresh->stock,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function writeOffUnit(Request $request, ProductStockService $stock)
    {
        $admin = auth('admin')->user();
        if (! $admin) {
            return response()->json(['message' => 'Не авторизован'], 403);
        }

        $request->validate([
            'code' => 'required|string|max:512',
            'reason_code' => 'nullable|string|max:32',
            'reason' => 'nullable|string|max:255',
            'type' => 'nullable|in:write_off,comp',
        ]);

        $type = $request->input('type', 'write_off') === 'comp'
            ? \App\Models\StockMovement::TYPE_COMP
            : \App\Models\StockMovement::TYPE_WRITE_OFF;

        $note = trim((string) ($request->input('reason') ?? ''));
        if ($note === '') {
            $note = $type === \App\Models\StockMovement::TYPE_COMP ? 'Угощение' : 'Списание';
        }

        try {
            $unit = $stock->writeOffUnit(
                $request->code,
                (int) $admin->id,
                $note,
                $request->input('reason_code'),
                $type
            );

            return response()->json([
                'status' => 'written_off',
                'product' => $unit->product,
                'new_stock' => (int) ($unit->product?->stock ?? 0),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Mid-shift write-off / complimentary for unmarked products (qty + reason).
     * Available to any admin on shift so losses don't become unexplained пересменка gaps.
     */
    public function adjustStock(Request $request, ProductStockService $stock)
    {
        $admin = auth('admin')->user();
        if (! $admin) {
            return response()->json(['message' => 'Не авторизован'], 403);
        }

        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'qty' => 'required|integer|min:1|max:999',
            'type' => 'required|in:write_off,comp',
            'reason_code' => 'nullable|string|max:32',
            'reason' => 'nullable|string|max:255',
        ]);

        $product = Product::findOrFail($data['product_id']);

        try {
            $result = $stock->adjustUnmarked(
                $product,
                (int) $data['qty'],
                (int) $admin->id,
                $data['type'] === 'comp'
                    ? \App\Models\StockMovement::TYPE_COMP
                    : \App\Models\StockMovement::TYPE_WRITE_OFF,
                $data['reason_code'] ?? null,
                $data['reason'] ?? null
            );

            return response()->json([
                'status' => 'adjusted',
                'product' => $result['product'],
                'new_stock' => (int) $result['product']->stock,
                'movement_id' => $result['movement']->id,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function updateStock(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:products,id',
            'amount' => 'required|integer',
        ]);

        $admin = auth('admin')->user();
        $amount = (int) $request->amount;
        $isLead = $admin && in_array($admin->role, ['supervisor', 'owner'], true);
        $product = Product::findOrFail($request->id);

        if ($product->requires_marking) {
            return response()->json([
                'message' => 'Маркированный товар принимается только сканом DataMatrix',
            ], 422);
        }

        if (! $isLead && $amount !== 1) {
            return response()->json([
                'message' => 'Приёмка немеченого товара — только +1 сканером EAN. Списание — через продажу или старшего.',
            ], 403);
        }

        if (! $isLead && $amount < 0) {
            return response()->json(['message' => 'Списание недоступно'], 403);
        }

        if (($product->stock + $amount) < 0) {
            return response()->json(['message' => 'Недостаточно товара на складе'], 422);
        }

        $product->increment('stock', $amount);

        return response()->json([
            'status' => 'success',
            'new_stock' => (int) $product->fresh()->stock,
        ]);
    }

    public function findByBarcode(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $code = ProductUnit::normalizeCode($request->code);
        $gtin = ProductUnit::extractGtin($code);

        $product = Product::query()->where('barcode', $code)->first();
        if (! $product && $gtin) {
            $product = Product::query()
                ->where(function ($q) use ($gtin) {
                    $q->where('barcode', $gtin)
                        ->orWhere('barcode', ltrim($gtin, '0'));
                })
                ->first();
        }

        if (! $product) {
            return response()->json(['message' => 'Объект не опознан. Код отсутствует в базе.'], 404);
        }

        return response()->json($product);
    }

    // ==========================================
    // 3. РЕЕСТР БОНУСОВ
    // ==========================================
    public function bonusLogs()
    {
        // Получаем логи с привязкой к юзеру и админу
        $logs = DB::table('bonus_logs')
            ->join('users', 'bonus_logs.user_id', '=', 'users.id')
            ->leftJoin('admins', 'bonus_logs.admin_id', '=', 'admins.id') // Замени 'admins' на 'users', если у тебя админы в таблице users
            ->select(
                'bonus_logs.*',
                'users.name as user_name',
                'users.phone as user_phone',
                'admins.name as admin_name'
            )
            ->orderByDesc('bonus_logs.created_at')
            ->get()
            ->map(function ($log) {
                // Форматируем под Vue структуру
                return [
                    'id'         => $log->id,
                    'minutes'    => $log->minutes,
                    'reason'     => $log->reason,
                    'created_at' => $log->created_at,
                    'user'       => ['name' => $log->user_name, 'phone' => $log->user_phone],
                    'admin'      => ['name' => $log->admin_name]
                ];
            });

        $todayMinutes = DB::table('bonus_logs')->whereDate('created_at', today())->sum('minutes');
        $monthMinutes = DB::table('bonus_logs')->whereMonth('created_at', now()->month)->sum('minutes');

        return Inertia::render('Admin/BonusLogs', [
            'logs'  => $logs,
            'stats' => [
                'today_minutes' => (int) $todayMinutes,
                'month_minutes' => (int) $monthMinutes
            ]
        ]);
    }
    // ==========================================
    // 4. ОЧЕРЕДЬ ЗАКАЗОВ (REACTOR MARKET)
    // ==========================================
    public function orders(ProductStockService $stock)
    {
        // Активная очередь: новые (pending) и в работе (cooking)
        $orders = DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->select(
                'orders.*',
                'users.name as user_name',
                'users.phone as user_phone'
            )
            ->whereIn('orders.status', ['pending', 'cooking'])
            ->orderBy('orders.created_at', 'asc')
            ->get()
            ->map(function ($order) use ($stock) {
                $labels = [
                    'pending' => 'В очереди',
                    'cooking' => 'В очереди',
                    'delivered' => 'Выполнен',
                    'cancelled' => 'Отменён',
                ];
                $items = Order::normalizeItems(
                    $order->items ?? null,
                    $order->product_name ?? null,
                    (float) ($order->price ?? 0)
                );
                $marking = $stock->markingFulfillmentProgress((int) $order->id, $items);

                return [
                    'id' => $order->id,
                    'product_name' => $order->product_name,
                    'items' => $items,
                    'price' => (float) ($order->price ?? 0),
                    'pc_name' => $order->pc_name,
                    'status' => $order->status,
                    'status_label' => $labels[$order->status] ?? $order->status,
                    'marking_progress' => $marking,
                    'marking_complete' => $stock->orderMarkingFullyScanned((int) $order->id, $items),
                    'user' => [
                        'name' => $order->user_name,
                        'phone' => $order->user_phone,
                    ],
                ];
            });

        return Inertia::render('Admin/Orders', [
            'orders' => $orders,
        ]);
    }

    public function updateOrderStatus(Request $request, $id, ProductStockService $stock)
    {
        $request->validate([
            'status' => 'required|in:pending,cooking,delivered,cancelled',
        ]);

        $order = DB::table('orders')->where('id', $id)->first();
        if (! $order) {
            return back()->withErrors(['status' => 'Заказ не найден']);
        }

        $items = Order::normalizeItems(
            $order->items ?? null,
            $order->product_name ?? null,
            (float) ($order->price ?? 0)
        );

        if ($request->status === 'delivered' && ! $stock->orderMarkingFullyScanned((int) $id, $items)) {
            return back()->withErrors([
                'status' => 'Сначала отсканируйте коды маркировки всех напитков из заказа',
            ]);
        }

        if ($request->status === 'cancelled') {
            DB::transaction(function () use ($id, $items, $stock) {
                $soldUnits = ProductUnit::query()
                    ->where('sold_order_id', (int) $id)
                    ->where('status', ProductUnit::STATUS_SOLD)
                    ->lockForUpdate()
                    ->get();

                foreach ($soldUnits as $unit) {
                    $unit->update([
                        'status' => ProductUnit::STATUS_AVAILABLE,
                        'sold_order_id' => null,
                        'sold_at' => null,
                    ]);
                }

                $stock->releaseReservationsForOrder((int) $id);
                $stock->restoreUnmarkedForOrder((int) $id, $items);

                foreach ($soldUnits->pluck('product_id')->unique() as $productId) {
                    $stock->syncMarkedStock((int) $productId);
                }
            });
        }

        if ($request->status === 'delivered') {
            $stock->releaseReservationsForOrder((int) $id);
        }

        DB::table('orders')->where('id', $id)->update([
            'status' => $request->status,
            'updated_at' => now(),
        ]);

        return back();
    }

    public function fulfillOrderScan(Request $request, $id, ProductStockService $stock)
    {
        $request->validate([
            'code' => 'required|string|max:512',
            'product_id' => 'nullable|integer|exists:products,id',
        ]);

        $order = DB::table('orders')->where('id', $id)->first();
        if (! $order) {
            return response()->json(['message' => 'Заказ не найден'], 404);
        }

        if (! in_array($order->status, ['pending', 'cooking'], true)) {
            return response()->json(['message' => 'Заказ уже закрыт'], 422);
        }

        $items = Order::normalizeItems(
            $order->items ?? null,
            $order->product_name ?? null,
            (float) ($order->price ?? 0)
        );

        $progress = $stock->markingFulfillmentProgress((int) $id, $items);
        if ($progress === []) {
            return response()->json(['message' => 'В заказе нет маркированных позиций'], 422);
        }

        $remainingByProduct = collect($progress)
            ->filter(fn ($row) => $row['remaining'] > 0)
            ->keyBy('product_id');

        if ($remainingByProduct->isEmpty()) {
            return response()->json([
                'message' => 'Все коды уже отсканированы',
                'marking_progress' => $progress,
                'marking_complete' => true,
            ], 422);
        }

        try {
            $code = ProductUnit::normalizeCode($request->code);
            $unit = ProductUnit::query()->where('marking_code', $code)->first();
            if (! $unit) {
                return response()->json(['message' => 'Код маркировки не найден на складе'], 422);
            }

            if ($request->filled('product_id') && (int) $unit->product_id !== (int) $request->product_id) {
                return response()->json(['message' => 'Код относится к другому товару'], 422);
            }

            if (! $remainingByProduct->has((int) $unit->product_id)) {
                return response()->json([
                    'message' => 'Этот товар не нужен в заказе или уже полностью отсканирован',
                ], 422);
            }

            $stock->sellUnitByMarkingCode((int) $id, $code, (int) $unit->product_id);

            $progressAfter = $stock->markingFulfillmentProgress((int) $id, $items);

            return response()->json([
                'status' => 'scanned',
                'order_id' => (int) $id,
                'product_id' => (int) $unit->product_id,
                'product_name' => Product::find($unit->product_id)?->name,
                'marking_progress' => $progressAfter,
                'marking_complete' => $stock->orderMarkingFullyScanned((int) $id, $items),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Скан КМ без выбора заказа: вешаем на самый старый открытый заказ, которому нужен этот товар.
     */
    public function autoFulfillScan(Request $request, ProductStockService $stock)
    {
        $request->validate([
            'code' => 'required|string|max:512',
        ]);

        $code = ProductUnit::normalizeCode($request->code);
        $unit = ProductUnit::query()->where('marking_code', $code)->first();
        if (! $unit) {
            return response()->json(['message' => 'Код маркировки не найден на складе'], 422);
        }
        if ($unit->status !== ProductUnit::STATUS_AVAILABLE) {
            return response()->json(['message' => 'Этот код уже выдан или списан'], 422);
        }

        $productId = (int) $unit->product_id;
        $orders = DB::table('orders')
            ->whereIn('status', ['pending', 'cooking'])
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($orders as $order) {
            $items = Order::normalizeItems(
                $order->items ?? null,
                $order->product_name ?? null,
                (float) ($order->price ?? 0)
            );
            $progress = $stock->markingFulfillmentProgress((int) $order->id, $items);
            $remaining = collect($progress)->firstWhere('product_id', $productId);
            if (! $remaining || (int) $remaining['remaining'] < 1) {
                continue;
            }

            try {
                $stock->sellUnitByMarkingCode((int) $order->id, $code, $productId);
                $progressAfter = $stock->markingFulfillmentProgress((int) $order->id, $items);

                return response()->json([
                    'status' => 'scanned',
                    'order_id' => (int) $order->id,
                    'product_id' => $productId,
                    'product_name' => Product::find($productId)?->name,
                    'marking_progress' => $progressAfter,
                    'marking_complete' => $stock->orderMarkingFullyScanned((int) $order->id, $items),
                ]);
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response()->json([
            'message' => 'Нет открытого заказа, которому нужен этот товар',
        ], 422);
    }
    public function getPcStatuses()
    {
        $computers = app(\App\Services\ComputerPowerService::class)->statusSnapshot();
        $fanOrphans = app(\App\Services\Fan\FanControlService::class)->orphanSnapshot();

        return response()->json([
            'computers' => $computers,
            'fan_orphans' => $fanOrphans,
        ]);
    }
    public function checkNewOrders()
    {
        // Считаем только те, что еще не приняты (статус pending)
        $count = DB::table('orders')->where('status', 'pending')->count();

        return response()->json(['count' => $count]);
    }

    // ==========================================
    // 5. SOS И HID-СИГНАЛЫ С ТЕРМИНАЛОВ (QML SHELL)
    // ==========================================

    /**
     * Активные (необработанные) SOS-вызовы и аномалии периферии для дашбоарда.
     */
    public function sosAlerts()
    {
        $sos = ComputerSosAlert::with('computer:id,name')
            ->whereNull('resolved_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function (ComputerSosAlert $alert) {
                return [
                    'id' => $alert->id,
                    'computer_id' => $alert->computer_id,
                    'pc_name' => $this->pcName($alert->computer?->name, $alert->computer_id),
                    'booking_id' => $alert->booking_id,
                    'reason_code' => $alert->reason_code,
                    'reason' => $alert->reason_label ?: $this->sosReasonLabel($alert->reason_code),
                    'created_at' => optional($alert->created_at)->toIso8601String(),
                    'time' => optional($alert->created_at)->format('H:i'),
                    'waiting_minutes' => $this->minutesAgo($alert->created_at),
                ];
            })
            ->values();

        $input = ComputerInputAlert::with('computer:id,name')
            ->whereNull('resolved_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function (ComputerInputAlert $alert) {
                return [
                    'id' => $alert->id,
                    'computer_id' => $alert->computer_id,
                    'pc_name' => $this->pcName($alert->computer?->name, $alert->computer_id),
                    'type' => $alert->type,
                    'type_label' => $this->inputAlertLabel($alert->type),
                    'severity' => $this->normalizeSeverity($alert->severity),
                    'details' => $this->inputAlertDetails($alert->payload),
                    'created_at' => optional($alert->created_at)->toIso8601String(),
                    'time' => optional($alert->created_at)->format('H:i'),
                ];
            })
            ->values();

        return response()->json([
            'sos' => $sos,
            'input' => $input,
            'counts' => AdminAlerts::counts(),
        ]);
    }

    /**
     * Оператор принял SOS-вызов.
     */
    public function ackSosAlert($id)
    {
        if (! $this->resolveSosAlert((int) $id)) {
            return response()->json(['message' => 'Сигнал не найден'], 404);
        }

        return response()->json(['status' => 'resolved', 'counts' => AdminAlerts::counts()]);
    }

    /**
     * Оператор принял сигнал о подмене/отключении периферии.
     */
    public function ackInputAlert($id)
    {
        if (! $this->resolveInputAlert((int) $id)) {
            return response()->json(['message' => 'Сигнал не найден'], 404);
        }

        return response()->json(['status' => 'resolved', 'counts' => AdminAlerts::counts()]);
    }

    // ==========================================
    // 6. РЕЕСТР ИНЦИДЕНТОВ
    // ==========================================
    public function incidents()
    {
        // Страховка на случай, если планировщик (reactor:check-quality) не запущен на машине клуба
        $this->syncLateOrderIncidents();

        $manual = DB::table('incidents')
            ->whereNull('resolved_at')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function ($row) {
                $createdAt = $row->created_at ? Carbon::parse($row->created_at) : now();

                return [
                    'id' => 'inc-'.$row->id,
                    'source' => 'incident',
                    'type' => $row->type,
                    'type_label' => $this->incidentTypeLabel($row->type),
                    'severity' => $this->normalizeSeverity($row->severity),
                    'description' => $row->description,
                    'order_id' => $row->order_id,
                    'pc_name' => null,
                    'created_at' => $createdAt->toIso8601String(),
                    'sort_ts' => $createdAt->getTimestamp(),
                    'resolved' => false,
                ];
            });

        $sos = ComputerSosAlert::with('computer:id,name')
            ->whereNull('resolved_at')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function (ComputerSosAlert $alert) {
                $createdAt = $alert->created_at ?: now();
                $pcName = $this->pcName($alert->computer?->name, $alert->computer_id);
                $reason = $alert->reason_label ?: $this->sosReasonLabel($alert->reason_code);

                return [
                    'id' => 'sos-'.$alert->id,
                    'source' => 'sos',
                    'type' => 'sos',
                    'type_label' => 'SOS с терминала',
                    'severity' => 'high',
                    'description' => "SOS {$pcName}: {$reason}",
                    'order_id' => null,
                    'pc_name' => $pcName,
                    'created_at' => $createdAt->toIso8601String(),
                    'sort_ts' => $createdAt->getTimestamp(),
                    'resolved' => false,
                ];
            });

        $input = ComputerInputAlert::with('computer:id,name')
            ->whereNull('resolved_at')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function (ComputerInputAlert $alert) {
                $createdAt = $alert->created_at ?: now();
                $pcName = $this->pcName($alert->computer?->name, $alert->computer_id);
                $label = $this->inputAlertLabel($alert->type);
                $details = $this->inputAlertDetails($alert->payload);

                return [
                    'id' => 'hid-'.$alert->id,
                    'source' => 'input',
                    'type' => $alert->type,
                    'type_label' => $label,
                    'severity' => $this->normalizeSeverity($alert->severity),
                    'description' => trim("{$label} на {$pcName}. {$details}"),
                    'order_id' => null,
                    'pc_name' => $pcName,
                    'created_at' => $createdAt->toIso8601String(),
                    'sort_ts' => $createdAt->getTimestamp(),
                    'resolved' => false,
                ];
            });

        $incidents = $manual
            ->concat($sos)
            ->concat($input)
            ->sortByDesc('sort_ts')
            ->values();

        return Inertia::render('Admin/Incidents', [
            'incidents' => $incidents,
        ]);
    }

    /**
     * Закрытие записи реестра. ID приходит с префиксом источника: inc-12 / sos-3 / hid-7.
     */
    public function resolveIncident($id)
    {
        [$source, $rawId] = $this->parseIncidentId((string) $id);

        $resolved = match ($source) {
            'sos' => $this->resolveSosAlert($rawId),
            'hid' => $this->resolveInputAlert($rawId),
            default => $this->resolveManualIncident($rawId),
        };

        if (! $resolved) {
            return response()->json(['message' => 'Запись не найдена'], 404);
        }

        return response()->json(['status' => 'resolved', 'counts' => AdminAlerts::counts()]);
    }

    // ==========================================
    // ХЕЛПЕРЫ АЛЕРТОВ / ИНЦИДЕНТОВ
    // ==========================================

    private function resolveSosAlert(int $id): bool
    {
        $alert = ComputerSosAlert::with('computer:id,name')->find($id);
        if (! $alert) {
            return false;
        }

        if (! $alert->resolved_at) {
            $alert->resolved_at = now();
            $alert->save();

            // SOS дублируется в общий канал вызовов — гасим и его, чтобы не звонить дважды
            $pcName = $this->pcName($alert->computer?->name, $alert->computer_id);
            DB::table('admin_calls')
                ->where('status', 'pending')
                ->where('pc_name', $pcName)
                ->where('message', 'SOS: '.$alert->reason_label)
                ->update(['status' => 'resolved', 'updated_at' => now()]);

            Log::info('[SOS-ACK]', [
                'alert_id' => $alert->id,
                'admin_id' => Auth::guard('admin')->id(),
            ]);
        }

        return true;
    }

    private function resolveInputAlert(int $id): bool
    {
        $alert = ComputerInputAlert::find($id);
        if (! $alert) {
            return false;
        }

        if (! $alert->resolved_at) {
            $alert->resolved_at = now();
            $alert->save();

            Log::info('[HID-ACK]', [
                'alert_id' => $alert->id,
                'admin_id' => Auth::guard('admin')->id(),
            ]);
        }

        return true;
    }

    private function resolveManualIncident(int $id): bool
    {
        $incident = DB::table('incidents')->where('id', $id)->first();
        if (! $incident) {
            return false;
        }

        DB::table('incidents')->where('id', $id)->update([
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('[INCIDENT-RESOLVED]', [
            'incident_id' => $id,
            'type' => $incident->type,
            'admin_id' => Auth::guard('admin')->id(),
        ]);

        return true;
    }

    private function parseIncidentId(string $id): array
    {
        if (preg_match('/^(inc|sos|hid)-(\d+)$/', $id, $matches)) {
            return [$matches[1], (int) $matches[2]];
        }

        return ['inc', (int) $id];
    }

    /**
     * Фиксация просроченных заказов (та же логика, что в команде reactor:check-quality).
     */
    private function syncLateOrderIncidents(): void
    {
        try {
            $lateOrders = DB::table('orders')
                ->where('status', 'pending')
                ->where('created_at', '<', now()->subMinutes(5))
                ->get(['id', 'product_name']);

            foreach ($lateOrders as $order) {
                $exists = DB::table('incidents')
                    ->where('type', 'late_order')
                    ->where('order_id', $order->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('incidents')->insert([
                    'type' => 'late_order',
                    'order_id' => $order->id,
                    'severity' => 'high',
                    'description' => "КРИТИЧЕСКАЯ ЗАДЕРЖКА: Заказ #{$order->id} ({$order->product_name}) не обработан за 5+ минут.",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('syncLateOrderIncidents: '.$e->getMessage());
        }
    }

    private function pcName(?string $name, ?int $computerId): string
    {
        return $name ?: ('PC-'.($computerId ?? 0));
    }

    private function sosReasonLabel(?string $code): string
    {
        return match ($code) {
            ComputerSosAlert::REASON_PERIPHERALS => 'Проблема с периферией',
            ComputerSosAlert::REASON_AUTH_HELP => 'Помощь с авторизацией',
            ComputerSosAlert::REASON_OTHER => 'Другая причина',
            default => 'Вызов оператора',
        };
    }

    private function inputAlertLabel(?string $type): string
    {
        return match ($type) {
            ComputerInputAlert::TYPE_DEVICE_CHANGED => 'Подмена периферии',
            ComputerInputAlert::TYPE_DISCONNECTED => 'Периферия отключена',
            ComputerInputAlert::TYPE_UNSTABLE => 'Нестабильная периферия',
            default => 'Аномалия периферии',
        };
    }

    private function inputAlertDetails($payload): string
    {
        if (! is_array($payload)) {
            return '';
        }

        $current = is_array($payload['current'] ?? null) ? $payload['current'] : [];
        $mice = is_array($current['mice'] ?? null) ? count($current['mice']) : 0;
        $keyboards = is_array($current['keyboards'] ?? null) ? count($current['keyboards']) : 0;
        $burst = (int) ($payload['burst_count'] ?? 0);

        return "Мышей: {$mice}, клавиатур: {$keyboards}, срабатываний за минуту: {$burst}";
    }

    private function incidentTypeLabel(?string $type): string
    {
        return match ($type) {
            'late_order' => 'Задержка сервиса',
            'inventory_discrepancy' => 'Расхождение склада',
            'low_stock' => 'Низкий остаток',
            'manual_balance_edit' => 'Ручная правка баланса',
            default => 'Нарушение протокола',
        };
    }

    private function normalizeSeverity(?string $severity): string
    {
        return match ($severity) {
            'critical', 'high' => 'high',
            'low', 'info' => 'low',
            default => 'medium',
        };
    }

    private function minutesAgo($timestamp): int
    {
        if (! $timestamp) {
            return 0;
        }

        return (int) abs(Carbon::parse($timestamp)->diffInMinutes(now()));
    }
}

