<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // === ПОДКЛЮЧЕНИЕ ГЛОБАЛЬНОЙ ШИНЫ INERTIA ===
        // Вот эта команда заставит Laravel отдавать данные юзера на ВСЕ страницы
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // 1. Куда редиректить НЕАВТОРИЗОВАННЫХ (Гостей)
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('admin') || $request->is('admin/*')) {
                return route('admin.login');
            }
            return route('login');
        });

        // 2. Куда редиректить УЖЕ АВТОРИЗОВАННЫХ (чтобы не видели форму входа)
        $middleware->redirectUsersTo(function (Request $request) {
            // Если залогинен админ — на его панель
            if (Auth::guard('admin')->check()) {
                return route('admin.dashboard');
            }
            // В остальных случаях (игрок) — на дашборд
            return route('dashboard');
        });

        // 3. Алиасы для посредников
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
