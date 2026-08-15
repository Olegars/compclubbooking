<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Support\AdminLocation;
use App\Support\ClubBrand;
use Illuminate\Database\Eloquent\Builder;

abstract class StoreController extends Controller
{
    protected function admin(): Admin
    {
        /** @var Admin $admin */
        $admin = auth('admin')->user();

        return $admin;
    }

    protected function locationId(): int
    {
        $id = AdminLocation::id($this->admin());
        abort_unless($id, 403, ClubBrand::name($this->admin()).': Локация не выбрана.');

        return $id;
    }

    protected function scopeClub(Builder $query, string $column = 'club_id'): Builder
    {
        return $query->where($column, $this->locationId());
    }
}
