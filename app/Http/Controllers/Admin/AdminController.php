<?php


namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function index()
    {
        // Статистика для дашборда админа
        $stats = [
            'total_revenue' => Transaction::where('amount', '>', 0)->sum('amount'),
            'active_sessions' => 12, // Сюда потом прикрутим реальный подсчет из Gizmo
            'new_users_today' => User::whereDate('created_at', today())->count(),
        ];

        return Inertia::render('Admin/Dashboard', [
            'stats' => $stats
        ]);
    }

    /**
     * Быстрый поиск пользователя по телефону
     */
    public function searchUser(Request $request)
    {
        $phone = $request->query('phone');

        $user = User::where('phone', 'like', "%{$phone}%")
            ->with('wallet')
            ->first();

        return response()->json($user);
    }

    // app/Http/Controllers/Admin/AdminController.php

    /**
     * Статус очереди чеков
     */
    public function kassaStatus()
    {
        // Здесь будет запрос к твоему сервису печати чеков
        return response()->json([
            'status' => 'online',
            'paper' => 'ok',
            'pending_checks' => 0
        ]);
    }

    /**
     * Подарочное время (Лояльность)
     */
    // app/Http/Controllers/Admin/AdminController.php

    public function giftTime(Request $request, GizmoService $gizmo) {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'minutes' => 'required|integer|min:1|max:20', // Жесткий лимит 20 мин
            'reason' => 'required|string',
            'pc_id' => 'required'
        ]);

        $admin = auth()->user();
        $minutes = $request->minutes;

        // 1. ПРОВЕРКА: Лимит админа на смену (допустим, 120 минут суммарно)
        $adminDailyUsed = GiftLog::where('admin_id', $admin->id)
            ->whereDate('created_at', today())
            ->sum('minutes');

        if ($adminDailyUsed + $minutes > 120) {
            return response()->json([
                'message' => 'Твой лимит исправлений на сегодня исчерпан (120 мин).'
            ], 403);
        }

        // 2. ПРОВЕРКА: Лимит на одного игрока (чтобы не кормить друга)
        $userDailyReceived = GiftLog::where('user_id', $request->user_id)
            ->whereDate('created_at', today())
            ->sum('minutes');

        if ($userDailyReceived + $minutes > 40) {
            return response()->json([
                'message' => 'Этот игрок сегодня уже получил максимум бонусов (40 мин).'
            ], 403);
        }

        // 3. ПРОВЕДЕНИЕ ОПЕРАЦИИ
        return DB::transaction(function () use ($request, $admin, $minutes, $gizmo) {
            // Логируем для истории
            GiftLog::create([
                'admin_id' => $admin->id,
                'user_id' => $request->user_id,
                'minutes' => $minutes,
                'reason' => $request->reason,
                'pc_name' => $request->pc_id
            ]);

            // Отправляем команду в Gizmo
            $success = $gizmo->startSession(
                User::find($request->user_id)->gizmo_id,
                $request->pc_id,
                $minutes
            );

            if (!$success) throw new \Exception("Gizmo API не ответил");

            return response()->json(['status' => 'success', 'remaining_fund' => 120 - ($adminDailyUsed + $minutes)]);
        });
    }

    public function fiscalMonitor()
    {
        // Статистика чеков из нашей базы за сегодня
        $todayStats = [
            'success' => Transaction::whereDate('created_at', today())->where('fiscal_status', 'success')->count(),
            'pending' => Transaction::whereDate('created_at', today())->where('fiscal_status', 'pending')->count(),
            'error' => Transaction::whereDate('created_at', today())->where('fiscal_status', 'error')->count(),
        ];

        return Inertia::render('Admin/FiscalMonitor', [
            'initialStats' => $todayStats
        ]);
    }

    /**
     * Прокси-запрос к KkmServer для получения статуса «железа»
     */
    public function getKktHardwareStatus()
    {
        try {
            // Запрашиваем состояние устройств у KkmServer
            $response = Http::post(env('KKM_SERVER_URL'), [
                "Command" => "GetDataKassa",
                "NumDevice" => 0,
                "User" => env('KKM_SERVER_USER'),
                "Password" => env('KKM_SERVER_PASS')
            ]);

            return $response->json();
        } catch (\Exception $e) {
            return response()->json(['Error' => 'KkmServer недоступен: ' . $e->getMessage()], 500);
        }
    }

}
