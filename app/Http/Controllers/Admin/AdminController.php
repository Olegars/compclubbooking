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
    public function giftTime(Request $request)
    {
        $admin = auth()->user();
        $minutes = $request->minutes;

        // 1. Защита: не более 20 минут за раз
        if ($minutes > 20) {
            return response()->json(['message' => 'Превышен разовый лимит (max 20 мин)'], 403);
        }

        // 2. Защита: лимит админа на смену (например, 120 минут)
        $alreadyGiftedToday = GiftLog::where('admin_id', $admin->id)
            ->whereDate('created_at', today())
            ->sum('minutes');

        if ($alreadyGiftedToday + $minutes > 120) {
            return response()->json(['message' => 'Твой дневной фонд исчерпан'], 403);
        }

        // 3. Защита: лимит на конкретного юзера (чтобы не кормить друга по 20 мин весь день)
        $userGiftsToday = GiftLog::where('user_id', $request->user_id)
            ->whereDate('created_at', today())
            ->sum('minutes');

        if ($userGiftsToday + $minutes > 40) {
            return response()->json(['message' => 'Этот пользователь уже получил максимум подарков сегодня'], 403);
        }

        return DB::transaction(function () use ($request, $admin, $minutes) {
            // Создаем лог для истории и аудита
            GiftLog::create([
                'admin_id' => $admin->id,
                'user_id' => $request->user_id,
                'minutes' => $minutes,
                'reason' => $request->reason
            ]);

            // Команда в Gizmo через наш сервис
            // ... (вызов GizmoService)

            return response()->json(['status' => 'ok']);
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
