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
        // Отдаем страницу Дашбоарда с базовой статистикой
        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'TOTAL_REVENUE' => 0, // Заглушка, потом привяжем к кассе
                'ACTIVE_SESSIONS' => 0,
                'NEW_USERS_TODAY' => User::whereDate('created_at', today())->count()
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

        return response()->json($user);
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
        $request->validate([
            'name'     => 'required|string|max:255',
            'category' => 'required|string',
            'price'    => 'required|numeric',
            'image'    => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048'
        ]);

        $data = $request->only(['name', 'category', 'price']);

        // Обработка загрузки файла
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = time() . '_' . $file->getClientOriginalName();
            // Сохраняем в public/images/shop
            $file->move(public_path('images/shop'), $filename);
            $data['image'] = '/images/shop/' . $filename;
        }

        // Обновляем или создаем (предполагаем, что таблица называется products)
        if ($request->id) {
            DB::table('products')->where('id', $request->id)->update($data);
        } else {
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
        // Загружаем только активные заказы (pending), чтобы не засорять экран доставленными
        $orders = DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->select(
                'orders.*',
                'users.name as user_name',
                'users.phone as user_phone'
            )
            ->where('orders.status', 'pending')
            ->orderBy('orders.created_at', 'asc') // Сначала старые заказы
            ->get()
            ->map(function($order) {
                // Упаковываем данные в формат, который ждет твой Vue-компонент Orders.vue
                return [
                    'id' => $order->id,
                    'product_name' => $order->product_name,
                    'pc_name' => $order->pc_name,
                    'status' => $order->status,
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
            'status' => 'required|in:delivered,cancelled'
        ]);

        // Обновляем статус заказа в базе
        DB::table('orders')->where('id', $id)->update([
            'status' => $request->status,
            'updated_at' => now()
        ]);

        // Inertia 'back()' автоматически перезагрузит данные на странице без полного рефреша
        return back();
    }
}
