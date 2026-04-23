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

        if ($request->code !== '0451') {
            return back()->withErrors(['code' => 'Неверный код']);
        }

        // Ищем юзера. Если не нашли - создаем с рандомной аватаркой
        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            $user = User::create([
                'phone' => $request->phone,
                'name' => 'Stalker_' . substr($request->phone, -4),
                'email' => $request->phone . '@reactor.club',
                'password' => bcrypt(str_random(16)),
                'avatar' => 'avatar_' . rand(1, 10) . '.png', // Рандом от 1 до 10
            ]);
        }

        if (!$user->gizmo_id) {
            $gizmoId = $gizmo->createUser([
                'username' => $user->name,
                'phone' => $user->phone
            ]);
            if ($gizmoId) { $user->update(['gizmo_id' => $gizmoId]); }
        }

        if (!$user->wallet) {
            Wallet::create(['user_id' => $user->id, 'balance' => 0]);
        }

        Auth::login($user, true);
        return redirect()->route('dashboard');
    }
}
