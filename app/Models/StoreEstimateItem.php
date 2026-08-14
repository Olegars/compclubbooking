<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreEstimateItem extends Model
{
    public const STATUSES = ['planned', 'from_stock', 'to_order', 'ordered', 'received'];

    public const STATUS_LABELS = [
        'planned' => 'План',
        'from_stock' => 'Резерв (склад)',
        'to_order' => 'К закупке',
        'ordered' => 'Заказано',
        'received' => 'Резерв',
    ];

    protected $fillable = [
        'store_estimate_id', 'type', 'name', 'part',
        'supplier_sku', 'supplier_part', 'supplier_name',
        'supplier_price', 'supplier_qty', 'sale_price', 'qty',
        'status', 'store_component_id', 'sort_order', 'notes',
    ];

    protected $casts = [
        'supplier_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
    ];

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(StoreEstimate::class, 'store_estimate_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(StoreComponent::class, 'store_component_id');
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(StorePurchaseItem::class);
    }
}
