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

        $balance = $user ? $user->availableBalance() : 0.0;

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? array_merge($user->toArray(), [
                    'balance' => $balance,
                ]) : null,
            ],
            // Shared for MainLayout Account Balance + top-up stub on every page
            'gizmo' => [
                'balance' => $balance,
            ],
            // ПЕРСОНАЛ
            'admin_user' => Auth::guard('admin')->user(),
        ]);
    }
}
