<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Club;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ClubBrand
{
    public static function name(?Admin $admin = null): string
    {
        try {
            $admin = $admin ?: auth('admin')->user();

            if ($admin) {
                $fromLocation = AdminLocation::resolve($admin)?->name;
                if (filled($fromLocation)) {
                    return trim((string) $fromLocation);
                }
            }

            if (! Schema::hasTable('clubs')) {
                return 'Клуб';
            }

            $fromDb = Club::operational()->orderBy('id')->value('name');
            if (filled($fromDb)) {
                return trim((string) $fromDb);
            }
        } catch (Throwable) {
            return 'Клуб';
        }

        return 'Клуб';
    }

    public static function nameForClub(?Club $club): string
    {
        $name = trim((string) ($club?->name ?? ''));

        return $name !== '' ? $name : self::name();
    }
}
