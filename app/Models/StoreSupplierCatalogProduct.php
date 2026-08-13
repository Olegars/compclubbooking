<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreSupplierCatalogProduct extends Model
{
    protected $fillable = [
        'sku', 'category_external_id', 'name', 'part', 'vendor',
        'rrp', 'price', 'stock_qty', 'price_synced_at',
        'warranty', 'multiplicity', 'barcodes', 'synced_at',
    ];

    protected $casts = [
        'rrp' => 'decimal:2',
        'price' => 'decimal:2',
        'synced_at' => 'datetime',
        'price_synced_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(StoreSupplierCatalogCategory::class, 'category_external_id', 'external_id');
    }
}
