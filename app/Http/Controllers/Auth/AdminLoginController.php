<?php


namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Illuminate\Validation\ValidationException;

class AdminLoginController extends Controller
{
    // Показать страницу входа
    public function showLoginForm()
    {
        return Inertia::render('Auth/AdminLogin');
    }

    // Обработка входа
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Пытаемся авторизовать именно через guard 'admin'
        if (Auth::guard('admin')->attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            $admin = Auth::guard('admin')->user();
            $home = $admin?->homeRoute() ?: 'admin.dashboard';

            return redirect()->intended(route($home));
        }

        throw ValidationException::withMessages([
            'email' => 'Неверные данные для входа в систему '.\App\Support\ClubBrand::name().'.',
        ]);
    }

    // Выход
    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
