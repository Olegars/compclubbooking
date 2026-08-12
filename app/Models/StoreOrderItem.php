<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreOrderItem extends Model
{
    protected $fillable = [
        'store_order_id', 'store_product_id', 'store_component_id', 'name', 'qty', 'price', 'serials',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'serials' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'store_product_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(StoreComponent::class, 'store_component_id');
    }
}
