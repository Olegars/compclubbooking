<?php

namespace App\Http\Controllers\Auth; // Проверь, чтобы путь совпадал с папкой

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function logout(Request $request)
    {
        // Выход для админа
        if (Auth::guard('admin')->check()) {
            Auth::guard('admin')->logout();
        }

        // Выход для обычного юзера
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Если выходим из-под админа — на логин админа, если юзер — на главную
        if ($request->is('admin/*')) {
            return redirect()->route('admin.login');
        }

        return redirect()->route('home');
    }
}
