<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SmsAuthController extends Controller
{
    // 1. Имитация отправки СМС (просто даем фронтенду команду "успех")
    public function sendCode(Request $request)
    {
        return response()->json([
            'message' => 'Код отправлен',
            'status' => 'success'
        ]);
    }
    public function verifyCode(Request $request)
    {
        $phone = preg_replace('/[^0-9]/', '', $request->phone);

        // Ищем юзера
        $user = User::where('phone', $phone)->first();

        // Если новый гость
        if (!$user) {
            $pin = (string) random_int(1000, 9999); // Генерируем брутальный 4-значный ПИН

            $user = User::create([
                'phone' => $phone,
                'name' => 'GUEST_' . substr($phone, -4),
                'password' => bcrypt(Str::random(16)), // Системный пароль Laravel (нам не нужен)
                'gizmo_pin' => $pin, // Сохраняем ПИН для Gizmo
            ]);

            // TODO: ЗДЕСЬ ДОЛЖЕН БЫТЬ GIZMO API CALL
            // Gizmo::createUser(['username' => $phone, 'password' => $pin]);
        }

        Auth::login($user, true);
        return redirect()->route('dashboard');
    }
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
