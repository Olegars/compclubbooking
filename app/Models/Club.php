<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Club extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'type',
        'address',
        'map_config',
        'viewbox',
    ];

    protected $casts = [
        'map_config' => 'array',
    ];

    public function hasStore(): bool
    {
        return in_array($this->type, ['store', 'both'], true);
    }

    public function hasClub(): bool
    {
        return in_array($this->type, ['club', 'both'], true);
    }

    public function computers(): HasMany
    {
        return $this->hasMany(Computer::class);
    }

    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }

    public function tariffPrices(): HasMany
    {
        return $this->hasMany(TariffPrice::class);
    }

    public function gameOffers(): HasMany
    {
        return $this->hasMany(ClubGame::class);
    }

    public function bookingGroups(): HasMany
    {
        return $this->hasMany(BookingGroup::class);
    }

    public function storeClients(): HasMany
    {
        return $this->hasMany(StoreClient::class);
    }

    public function storeProducts(): HasMany
    {
        return $this->hasMany(StoreProduct::class);
    }

    public function storeOrders(): HasMany
    {
        return $this->hasMany(StoreOrder::class);
    }

    public function storeWarranties(): HasMany
    {
        return $this->hasMany(StoreWarranty::class);
    }

    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class);
    }
}
