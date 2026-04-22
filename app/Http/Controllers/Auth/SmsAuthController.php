<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Services\GizmoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SmsAuthController extends Controller
{
    public function verifyCode(Request $request, GizmoService $gizmo)
    {
        $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string',
        ]);

        // 1. Проверка мастер-кода или кода из кэша
        if ($request->code !== '0451') {
            // Здесь должна быть логика проверки реального кода из Redis/Cache
            return back()->withErrors(['code' => 'Неверный код']);
        }

        // 2. Ищем или создаем пользователя в Laravel
        $user = User::firstOrCreate(
            ['phone' => $request->phone],
            [
                'name' => 'Stalker_' . substr($request->phone, -4),
                'email' => $request->phone . '@reactor.club', // Заглушка для email
                'password' => bcrypt(str_random(16)),
            ]
        );

        // 3. СИНХРОНИЗАЦИЯ С GIZMO
        if (!$user->gizmo_id) {
            $gizmoId = $gizmo->createUser([
                'username' => $user->name,
                'phone' => $user->phone
            ]);

            if ($gizmoId) {
                $user->update(['gizmo_id' => $gizmoId]);
            }
        }

        // 4. СОЗДАНИЕ КОШЕЛЬКА (если еще нет)
        if (!$user->wallet) {
            Wallet::create([
                'user_id' => $user->id,
                'balance' => 0
            ]);
        }

        // 5. Авторизуем
        Auth::login($user, true);

        return redirect()->route('dashboard');
    }
}
