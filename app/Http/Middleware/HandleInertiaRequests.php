<?php

namespace App\Http\Middleware;

use App\Support\AdminAlerts;
use App\Support\AdminLocation;
use App\Support\AdminShift;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Illuminate\Support\Facades\Auth;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = Auth::guard('web')->user();
        $admin = Auth::guard('admin')->user();

        $balance = $user ? $user->availableBalance() : 0.0;
        $location = $admin ? AdminLocation::resolve($admin) : null;
        $isOwner = $admin && $admin->role === 'owner';
        $canAccessClub = (bool) ($admin && $admin->role === 'admin' && $admin->hasFullClubOps());
        $canAccessStore = $admin && (
            $isOwner
            || ($admin->canAccessStore() && $location && $location->hasStore() && ! $admin->isSalaryOnly())
        );

        return array_merge(parent::share($request), [
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
            ],
            'auth' => [
                'user' => $user ? array_merge($user->toArray(), [
                    'balance' => $balance,
                ]) : null,
            ],
            'admin_user' => $admin ? [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'role_label' => $admin->roleLabel(),
                'club_id' => $admin->club_id,
            ] : null,
            'club_name' => filled($location?->name)
                ? trim((string) $location->name)
                : \App\Support\ClubBrand::name($admin),
            'can_access_club' => $canAccessClub,
            'can_access_store' => $canAccessStore,
            'has_full_club_ops' => (bool) ($admin && $admin->hasFullClubOps()),
            'is_salary_only' => (bool) ($admin && $admin->isSalaryOnly()),
            'admin_location' => $location ? [
                'id' => $location->id,
                'name' => $location->name,
                'slug' => $location->slug,
                'type' => $location->type,
                'has_store' => $location->hasStore(),
                'has_club' => $location->hasClub(),
            ] : null,
            'admin_locations' => $isOwner
                ? fn () => collect(AdminLocation::listForOwner($admin))->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'type' => $c->type,
                ])->values()->all()
                : [],
            'admin_alerts' => $admin ? fn () => AdminAlerts::counts() : null,
            'admin_shift' => $admin ? fn () => AdminShift::current($admin->id) : null,
        ]);
    }
}
