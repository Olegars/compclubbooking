<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Club;

class ClubBrand
{
    public static function name(?Admin $admin = null): string
    {
        $admin = $admin ?: auth('admin')->user();

        if ($admin) {
            $fromLocation = AdminLocation::resolve($admin)?->name;
            if (filled($fromLocation)) {
                return trim((string) $fromLocation);
            }
        }

        $fromDb = Club::query()->orderBy('id')->value('name');
        if (filled($fromDb)) {
            return trim((string) $fromDb);
        }

        return 'Клуб';
    }
}
