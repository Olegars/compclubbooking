<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictPublicInAdminApp
{
    public function handle(Request $request, Closure $next): Response
    {
        $ua = (string) $request->userAgent();
        if (! str_contains($ua, 'CompClubAdmin')) {
            return $next($request);
        }

        if ($request->is(
            'admin',
            'admin/*',
            'admin-app.apk',
            'admin-app.json',
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
