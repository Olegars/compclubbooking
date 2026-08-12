<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Club;
use Illuminate\Support\Facades\Session;

class AdminLocation
{
    public static function resolve(?Admin $admin = null): ?Club
    {
        $admin = $admin ?: auth('admin')->user();
        if (! $admin) {
            return null;
        }

        if ($admin->club_id) {
            return Club::query()->find($admin->club_id);
        }

        if ($admin->role === 'owner') {
            $sessionId = Session::get('admin_location_id');
            if ($sessionId) {
                $fromSession = Club::query()->find($sessionId);
                if ($fromSession) {
                    return $fromSession;
                }
            }

            return Club::query()->orderBy('id')->first();
        }

        return Club::query()->orderBy('id')->first();
    }

    public static function id(?Admin $admin = null): ?int
    {
        return self::resolve($admin)?->id;
    }

    public static function switch(int $clubId, ?Admin $admin = null): bool
    {
        $admin = $admin ?: auth('admin')->user();
        if (! $admin || $admin->role !== 'owner' || $admin->club_id) {
            return false;
        }

        if (! Club::query()->whereKey($clubId)->exists()) {
            return false;
        }

        Session::put('admin_location_id', $clubId);

        return true;
    }

    public static function listForOwner(?Admin $admin = null): array
    {
        $admin = $admin ?: auth('admin')->user();
        if (! $admin || $admin->role !== 'owner') {
            return [];
        }

        return Club::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'type', 'address'])
            ->all();
    }
}
