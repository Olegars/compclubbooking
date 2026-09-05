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
        $admin = auth('admin')->user();

        if (! $admin || ! in_array($admin->role, $roles, true)) {
            abort(403, \App\Support\ClubBrand::name($admin).': У вас нет допуска к этому разделу.');
        }

        return $next($request);
    }
}
