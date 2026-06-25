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
        $user = Auth::guard('web')->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? array_merge($user->toArray(), [
                    // Принудительно берем deposit_balance из связи wallet
                    'balance' => (float)($user->wallet?->deposit_balance ?? 0),
                ]) : null,
            ],
            // ПЕРСОНАЛ
            'admin_user' => Auth::guard('admin')->user(),
            // Пропсы для истории и броней (проверь, чтобы они были в контроллере или здесь)
        ]);
    }
}
