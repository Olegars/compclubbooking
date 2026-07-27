<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;
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

        // Загружаем реальные ПК из базы
        $computers = DB::table('computers')
            ->where('club_id', $clubId)
            ->select('id', 'name', 'status') // Предполагаем, что колонка status есть (available/busy)
            ->orderBy('name', 'asc')
            ->get();

        return Inertia::render('Admin/Dashboard', [
            'computers' => $computers,
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

        // *Здесь позже будет API-запрос к Gizmo для фактического начисления времени*

        return response()->json(['message' => 'Бонус успешно начислен и залогирован']);
    }

    // ==========================================
    // 2. СКЛАД МАРКЕТА (ИНВЕНТАРЬ)
    // ==========================================
    public function inventory()
    {
        return Inertia::render('Admin/Inventory');
    }

    public function saveProduct(Request $request)
    {
        // 1. Валидируем данные. image здесь — это загружаемый файл изображения
        $request->validate([
            'id'       => 'nullable|integer',
            'name'     => 'required|string|max:255',
            'category' => 'required|string',
            'price'    => 'required|numeric',
            'stock'    => 'nullable|integer|min:0',
            'image'    => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048' // Проверка файла
        ]);

        $data = [
            'name'     => $request->name,
            'category' => $request->category,
            'price'    => $request->price,
            'stock'    => $request->stock ?? 0,
            'barcode'  => $request->barcode ?? null,
        ];

        // 2. ОБРАБОТКА И ЗАГРУЗКА ФАЙЛА КАРТИНКИ
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = time() . '_' . $file->getClientOriginalName();

            // 1. Физический путь для сохранения на сервере
            $targetPath = public_path('images/shop');

            // Создаем папку, если её нет, и даем права 0755
            if (!file_exists($targetPath)) {
                mkdir($targetPath, 0755, true);
            }

            // 2. Перемещаем файл
            $file->move($targetPath, $filename);

            // 3. Сохраняем в базу БЕЗ ведущего слэша (чистый относительный путь)
            $data['image'] = 'images/shop/' . $filename;
        }

        // 3. СОХРАНЕНИЕ В БАЗУ ДАННЫХ
        if ($request->id) {
            // Если товар редактируется
            DB::table('products')->where('id', $request->id)->update($data);
        } else {
            // Если создается новый товар и картинка не была загружена — ставим пустую строку вместо null
            if (!isset($data['image'])) {
                $data['image'] = '';
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
    public function orders()
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
            ->map(function($order) {
                $labels = [
                    'pending' => 'Принят',
                    'cooking' => 'В работе',
                    'delivered' => 'Выполнен',
                    'cancelled' => 'Отменён',
                ];
                return [
                    'id' => $order->id,
                    'product_name' => $order->product_name,
                    'pc_name' => $order->pc_name,
                    'status' => $order->status,
                    'status_label' => $labels[$order->status] ?? $order->status,
                    'user' => [
                        'name' => $order->user_name,
                        'phone' => $order->user_phone,
                    ]
                ];
            });

        return Inertia::render('Admin/Orders', [
            'orders' => $orders
        ]);
    }

    public function updateOrderStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,cooking,delivered,cancelled'
        ]);

        DB::table('orders')->where('id', $id)->update([
            'status' => $request->status,
            'updated_at' => now()
        ]);

        return back();
    }
    public function getPcStatuses()
    {
        // Возвращаем только ID и статус всех ПК клуба
        $statuses = DB::table('computers')
            ->select('id', 'status')
            ->get();

        return response()->json($statuses);
    }
    public function checkNewOrders()
    {
        // Считаем только те, что еще не приняты (статус pending)
        $count = DB::table('orders')->where('status', 'pending')->count();

        return response()->json(['count' => $count]);
    }
    // resources/app/Http/Controllers/Admin/AdminController.php

    public function updateStock(Request $request)
    {
        // Важно: integer позволяет принимать отрицательные числа (например, -1)
        $request->validate([
            'id' => 'required|exists:products,id',
            'amount' => 'required|integer',
        ]);

        $product = DB::table('products')->where('id', $request->id);
        $current = $product->first();

        // Проверка на уход в минус
        if (($current->stock + $request->amount) < 0) {
            return response()->json(['message' => 'Недостаточно товара на складе'], 422);
        }

        // Используем increment, он отлично понимает отрицательные числа (добавляет -1)
        $product->increment('stock', (int)$request->amount);

        return response()->json([
            'status' => 'success',
            'new_stock' => $current->stock + $request->amount
        ]);
    }
    public function findByBarcode(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        // Ищем товар по штрих-коду
        $product = \App\Models\Product::where('barcode', $request->code)->first();

        if (!$product) {
            return response()->json(['message' => 'Объект не опознан. Код отсутствует в базе.'], 404);
        }

        return response()->json($product);
    }
    public function incidents()
    {
        // Пока передаем пустой массив, чтобы Vue не ругался на отсутствие данных.
        // Позже мы подключим сюда базу данных (модель Incident).
        return \Inertia\Inertia::render('Admin/Incidents', [
            'incidents' => []
        ]);
    }
}

