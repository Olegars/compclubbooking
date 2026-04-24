<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Illuminate\Support\Facades\DB;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        // Убрали dd(). Теперь мы просто склеиваем стандартные ошибки Inertia
        // с твоими кастомными данными (auth и gizmo) и отправляем их во Vue.
        return array_merge(parent::share($request), [

            // 1. Глобальная авторизация
            'auth' => [
                'user' => $request->user(),
            ],

            // 2. Глобальный баланс Gizmo
            'gizmo' => [
                'balance' => $request->user() && $request->user()->wallet ? $request->user()->wallet->balance : 0,
            ],

        ]);
    }
}
