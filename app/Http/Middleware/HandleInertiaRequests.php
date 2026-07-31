<?php

namespace App\Http\Middleware;

use App\Support\AdminAlerts;
use App\Support\AdminShift;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Illuminate\Support\Facades\Auth;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = Auth::guard('web')->user();
        $admin = Auth::guard('admin')->user();

        $balance = $user ? $user->availableBalance() : 0.0;

        return array_merge(parent::share($request), [
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
            ],
            'auth' => [
                'user' => $user ? array_merge($user->toArray(), [
                    'balance' => $balance,
                ]) : null,
            ],
            // ПЕРСОНАЛ
            // Отдаём только то, что рисует шапка: ставки и тип оплаты на клиенте не нужны.
            'admin_user' => $admin ? [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
            ] : null,
            // Счётчики для бейджей сайдбара админки (SOS / очередь заказов / инциденты).
            // Замыкание: Inertia считает их только когда проп реально уходит на клиент,
            // поэтому опрос /admin/api/* не тянет лишние запросы в базу.
            'admin_alerts' => $admin ? fn () => AdminAlerts::counts() : null,
            // Текущая смена для индикатора в шапке (по тому же принципу ленивого замыкания).
            'admin_shift' => $admin ? fn () => AdminShift::current($admin->id) : null,
        ]);
    }
}
