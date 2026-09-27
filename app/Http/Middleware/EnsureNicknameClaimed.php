<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Новый гость подтверждает или правит предложенный ник до кабинета и брони.
 * API шелла и бара не трогаем: у них нет браузерной сессии игрока.
 */
class EnsureNicknameClaimed
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('auth/nickname', 'logout', 'api/*')) {
            return $next($request);
        }

        $user = $request->user('web');
        if ($user && $user->nickname_pending) {
            return redirect()->route('auth.nickname');
        }

        return $next($request);
    }
}
