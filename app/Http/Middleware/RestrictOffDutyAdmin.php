<?php

namespace App\Http\Middleware;

use App\Support\AdminShift;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Неактивный админ и стажёр — только личный кабинет (плюс приёмка смены / выход в смену).
 */
class RestrictOffDutyAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = auth('admin')->user();
        if (! $admin) {
            return $next($request);
        }

        if ($admin->isFired()) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(403, 'Аккаунт уволен.');
            }

            return redirect()->route('admin.login')
                ->with('error', 'Аккаунт уволен. Вход закрыт.');
        }

        if ($frozen = $this->frozenHandoverResponse($request, $admin)) {
            return $frozen;
        }

        if ($admin->hasFullClubOps()) {
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
            ->with('error', 'Сейчас доступен только личный кабинет.');
    }

    private function isAllowed(Request $request, $admin): bool
    {
        $path = '/'.ltrim($request->path(), '/');

        $shared = [
            '/admin/salary',
            '/admin/salary/withdraw',
            '/admin/logout',
            '/admin/api/shifts/status',
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
            '/admin/api/shifts/begin',
            '/admin/api/shifts/scan',
            '/admin/api/shifts/count',
            '/admin/api/shifts/complete',
            '/admin/api/shifts/status',
        ]);
    }

    private function frozenHandoverResponse(Request $request, $admin): ?Response
    {
        $shift = AdminShift::openShift();
        if (! $shift || $shift->status !== 'transferring') {
            return null;
        }
        if ((int) $shift->admin_id !== (int) $admin->id) {
            return null;
        }
        if ($shift->incoming_admin_id && (int) $shift->incoming_admin_id === (int) $admin->id) {
            return null;
        }

        $path = '/'.ltrim($request->path(), '/');
        if ($this->matches($path, ['/admin/logout', '/admin/api/shifts/status'])) {
            return null;
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return null;
        }

        if ($request->expectsJson()) {
            abort(423, 'Идёт передача смены.');
        }

        return back()->with('error', 'Идёт передача смены — операции остановлены.');
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
