<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Неактивный админ и стажёр — только «Моя зарплата» (плюс приёмка смены / выход в смену).
 */
class RestrictOffDutyAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = auth('admin')->user();
        if (! $admin || $admin->hasFullClubOps()) {
            return $next($request);
        }

        if (! $admin->isSalaryOnly()) {
            return $next($request);
        }

        if ($this->isAllowed($request, $admin)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Доступ только у активного админа на смене.');
        }

        return redirect()->route('admin.salary')
            ->with('error', 'Сейчас доступна только страница «Моя зарплата».');
    }

    private function isAllowed(Request $request, $admin): bool
    {
        $path = '/'.ltrim($request->path(), '/');

        $shared = [
            '/admin/salary',
            '/admin/salary/withdraw',
            '/admin/logout',
        ];

        if ($this->matches($path, $shared)) {
            return true;
        }

        if ($admin->isIntern()) {
            return $this->matches($path, [
                '/admin/shifts/intern/join',
                '/admin/shifts/intern/leave',
            ]);
        }

        // Неактивный админ может принять смену (пересменка) — иначе никто не станет активным.
        return $this->matches($path, [
            '/admin/shifts/transfer',
            '/admin/api/shifts/complete',
        ]);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function matches(string $path, array $allowed): bool
    {
        foreach ($allowed as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
