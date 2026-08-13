<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreSupplierCatalogProduct extends Model
{
    protected $fillable = [
        'sku', 'category_external_id', 'name', 'part', 'vendor',
        'rrp', 'price', 'stock_qty', 'price_synced_at',
        'warranty', 'multiplicity', 'barcodes',
        'has_image', 'image_path', 'image_synced_at',
        'case_color', 'case_glass', 'case_form', 'case_attrs_at',
        'synced_at',
    ];

    protected $casts = [
        'rrp' => 'decimal:2',
        'price' => 'decimal:2',
        'has_image' => 'boolean',
        'synced_at' => 'datetime',
        'price_synced_at' => 'datetime',
        'image_synced_at' => 'datetime',
        'case_attrs_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(StoreSupplierCatalogCategory::class, 'category_external_id', 'external_id');
    }
}
