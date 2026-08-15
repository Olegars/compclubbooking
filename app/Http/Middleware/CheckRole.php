<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Обработка запроса.
     * Параметр ...$roles позволяет передавать список разрешенных ролей через запятую.
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        // Получаем текущего авторизованного сотрудника (используя твой guard 'admin')
        $admin = auth('admin')->user();

        // Если админ не авторизован ИЛИ его роль не входит в список разрешенных
        if (!$admin || !in_array($admin->role, $roles)) {
            // Выдаем ошибку 403 (Доступ запрещен)
            abort(403, \App\Support\ClubBrand::name($admin).': У вас нет допуска к этому разделу.');
        }

        return $next($request);
    }
}
