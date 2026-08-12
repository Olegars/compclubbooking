<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreSupplierCatalogCategory extends Model
{
    protected $fillable = [
        'external_id', 'parent_external_id', 'name', 'leaf', 'synced_at',
    ];

    protected $casts = [
        'leaf' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(StoreSupplierCatalogProduct::class, 'category_external_id', 'external_id');
    }
}
