<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreAvitoProductAttr extends Model
{
    protected $fillable = [
        'sku', 'type', 'socket', 'ddr', 'ram_gb', 'wattage', 'form',
        'avito_brand', 'avito_model', 'avito_code', 'source', 'mapped_at',
    ];

    protected $casts = [
        'mapped_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(StoreSupplierCatalogProduct::class, 'sku', 'sku');
    }
}
