<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Тип помещения: формат посадки, а не железо. Экземпляры на карте — Space.
 */
class Zone extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'color',
        'buyout_discount_percent',
        'sort',
    ];

    protected $casts = [
        'buyout_discount_percent' => 'decimal:2',
        'sort' => 'integer',
    ];

    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(TariffPrice::class);
    }

    public function allowsBuyout(): bool
    {
        return $this->buyout_discount_percent !== null
            && (float) $this->buyout_discount_percent > 0;
    }
}
