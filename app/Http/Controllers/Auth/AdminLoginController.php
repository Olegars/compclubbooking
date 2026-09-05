<?php


namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Club;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
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
            $admin = Auth::guard('admin')->user();
            if ($admin?->isFired()) {
                Auth::guard('admin')->logout();

                throw ValidationException::withMessages([
                    'email' => 'Аккаунт уволен. Вход закрыт.',
                ]);
            }

            $request->session()->regenerate();

            $home = $admin?->homeRoute() ?: 'admin.dashboard';

            return redirect()->intended(route($home));
        }

        throw ValidationException::withMessages([
            'email' => 'Неверные данные для входа в систему '.\App\Support\ClubBrand::name().'.',
        ]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:admins,email'],
            'password' => ['required', 'confirmed', Password::min(6)],
        ], [
            'email.unique' => 'Этот email уже зарегистрирован',
            'password.confirmed' => 'Пароли не совпадают',
        ]);

        $clubId = Club::query()->orderBy('id')->value('id');

        $admin = Admin::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => Admin::ROLE_INTERN,
            'club_id' => $clubId,
            'is_official_employee' => false,
            'base_rate' => 1500,
            'pay_type' => 'shift',
            'employment_pending' => true,
        ]);

        Auth::guard('admin')->login($admin);
        $request->session()->regenerate();

        return redirect()->route('admin.salary');
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
