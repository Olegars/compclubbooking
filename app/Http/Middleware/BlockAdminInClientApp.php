<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockAdminInClientApp
{
    public function handle(Request $request, Closure $next): Response
    {
        $ua = (string) $request->userAgent();
        if (str_contains($ua, 'CompClubClient') && $request->is('admin', 'admin/*')) {
            return redirect('/');
        }

        return $next($request);
    }
}
