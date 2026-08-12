<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorePurchaseItem extends Model
{
    public const STATUSES = ['pending', 'ordered', 'received'];

    protected $fillable = [
        'store_purchase_id', 'store_estimate_item_id', 'supplier_sku',
        'name', 'qty', 'price', 'status', 'store_component_id',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(StorePurchase::class, 'store_purchase_id');
    }

    public function estimateItem(): BelongsTo
    {
        return $this->belongsTo(StoreEstimateItem::class, 'store_estimate_item_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(StoreComponent::class, 'store_component_id');
    }
}
