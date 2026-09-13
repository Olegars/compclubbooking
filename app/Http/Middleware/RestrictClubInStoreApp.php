<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictClubInStoreApp
{
    public function handle(Request $request, Closure $next): Response
    {
        $ua = (string) $request->userAgent();
        if (! str_contains($ua, 'CompClubStore')) {
            return $next($request);
        }

        if ($request->is(
            'store',
            'store/*',
            'admin/salary',
            'admin/salary/*',
            'admin/store',
            'admin/store/*',
            'admin/logout',
            'admin/api',
            'admin/api/*',
            'admin/docs',
            'admin/docs/*',
            'store-app.apk',
            'store-app.json',
            'build/*',
            'hot',
            'storage/*',
            'favicon.ico',
            'up',
            'robots.txt',
        )) {
            return $next($request);
        }

        return redirect()->route('store.login');
    }
}
