<?php
// app/Http/Middleware/AdminMiddleware.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Проверяем именно админский гуард
        if (!Auth::guard('admin')->check()) {
            // Если это не админ, кидаем на страницу входа для персонала
            // Или просто 403, если зашел обычный юзер
            return $request->expectsJson()
                ? abort(403, 'Unauthorized admin access.')
                : redirect()->route('admin.login');
        }

        return $next($request);
    }
}
