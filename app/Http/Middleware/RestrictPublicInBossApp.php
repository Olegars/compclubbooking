<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictPublicInBossApp
{
    public function handle(Request $request, Closure $next): Response
    {
        $ua = (string) $request->userAgent();
        if (! str_contains($ua, 'CompClubBoss')) {
            return $next($request);
        }

        if ($request->is(
            'admin',
            'admin/*',
            'store',
            'store/*',
            'boss-app.apk',
            'boss-app.json',
            'build/*',
            'hot',
            'storage/*',
            'favicon.ico',
            'up',
            'robots.txt',
        )) {
            return $next($request);
        }

        return redirect()->route('admin.login');
    }
}
