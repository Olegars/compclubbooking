<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware; // <-- Удали эту строку и напиши заново вручную

class HandleInertiaRequests extends Middleware
{
    // Основной шаблон (resources/views/app.blade.php)
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user(),
            ],
            'gizmo' => [
                // Обращаемся к балансу через кошелек (wallet)
                'balance' => $request->user()?->wallet?->balance ?? 0,
                'bonus' => 0,
                'current_pc' => 'NONE',
                'spent_time' => 0
            ],
            // Добавляем информацию о выбранном клубе
            'currentClub' => $request->session()->get('current_club'),
            'availableClubs' => \DB::table('clubs')->select('id', 'name', 'slug')->get(),
        ]);
    }
}
