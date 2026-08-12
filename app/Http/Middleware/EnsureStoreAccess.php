<?php

namespace App\Http\Middleware;

use App\Support\AdminLocation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStoreAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = auth('admin')->user();
        if (! $admin || ! $admin->canAccessStore()) {
            abort(403, 'REACTOR: Нет доступа к магазину.');
        }

        $location = AdminLocation::resolve($admin);
        if (! $location || ! $location->hasStore()) {
            abort(403, 'REACTOR: У этой локации магазин не включён.');
        }

        return $next($request);
    }
}
