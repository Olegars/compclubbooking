<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBatch extends Model
{
    protected $fillable = [
        'product_id',
        'supplier_id',
        'stock_movement_id',
        'product_unit_id',
        'qty_remaining',
        'unit_cost',
        'received_at',
    ];

    protected $casts = [
        'qty_remaining' => 'integer',
        'unit_cost' => 'float',
        'received_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
