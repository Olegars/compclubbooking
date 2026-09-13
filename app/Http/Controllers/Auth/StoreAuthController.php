<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Club;
use App\Support\ClubBrand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StoreAuthController extends Controller
{
    public function showLoginForm()
    {
        return Inertia::render('Auth/StoreLogin');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::guard('admin')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Неверные данные для входа в магазин '.ClubBrand::name().'.',
            ]);
        }

        $admin = Auth::guard('admin')->user();
        if ($admin?->isFired()) {
            Auth::guard('admin')->logout();

            throw ValidationException::withMessages([
                'email' => 'Аккаунт уволен. Вход закрыт.',
            ]);
        }

        if (! $admin?->isStoreRole()) {
            Auth::guard('admin')->logout();

            throw ValidationException::withMessages([
                'email' => 'Админы клуба входят на странице /admin/login.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route($admin->homeRoute() ?: 'store.cabinet'));
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:admins,email'],
            'password' => ['required', 'confirmed', Password::min(6)],
            'role' => ['required', 'in:assembler,store_manager'],
        ], [
            'email.unique' => 'Этот email уже зарегистрирован',
            'password.confirmed' => 'Пароли не совпадают',
            'role.in' => 'Выберите должность: сборщик или менеджер',
        ]);

        $club = Club::query()
            ->whereIn('type', ['store', 'both'])
            ->orderByRaw("CASE WHEN type = 'store' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();

        if (! $club) {
            throw ValidationException::withMessages([
                'email' => 'Локация магазина ещё не настроена. Обратитесь к владельцу.',
            ]);
        }

        $role = $data['role'];
        $admin = Admin::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $role,
            'club_id' => $club->id,
            'is_official_employee' => false,
            'base_rate' => Admin::defaultRateFor($role),
            'pay_type' => $role === 'senior_manager' ? 'monthly' : 'shift',
            'employment_pending' => true,
        ]);

        Auth::guard('admin')->login($admin);
        $request->session()->regenerate();

        return redirect()->route('store.hire');
    }
}
