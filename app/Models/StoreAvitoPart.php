<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreAvitoPart extends Model
{
    public const TYPES = ['cpu', 'gpu', 'ram', 'ssd', 'psu'];

    protected $fillable = [
        'type', 'code', 'label', 'socket', 'ddr', 'ram_gb', 'capacity_gb', 'wattage',
        'avito_brand', 'avito_model', 'avito_code', 'sort_order', 'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function configsAsCpu(): HasMany
    {
        return $this->hasMany(StoreAvitoConfig::class, 'cpu_part_id');
    }
}
