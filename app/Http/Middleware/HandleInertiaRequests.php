<?php

namespace App\Http\Middleware;

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
        return array_merge(parent::share($request), [
            // Игроки
            'auth' => [
                'user' => Auth::guard('web')->user(),
            ],
            // Баланс Gizmo
            'gizmo' => [
                'balance' => Auth::guard('web')->user()?->wallet?->balance ?? 0,
            ],
            // ПЕРСОНАЛ (Тот самый Boss)
            'admin_user' => Auth::guard('admin')->user(),
        ]);
    }
}
