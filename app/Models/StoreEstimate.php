<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreEstimate extends Model
{
    public const STATUSES = ['draft', 'agreed', 'procuring', 'ready', 'converted', 'cancelled'];

    public const STATUS_LABELS = [
        'draft' => 'Черновик',
        'agreed' => 'Согласовано',
        'procuring' => 'В закупке',
        'ready' => 'Готова к сборке',
        'converted' => 'В заказ',
        'cancelled' => 'Отменена',
    ];

    protected $fillable = [
        'club_id', 'store_client_id', 'created_by', 'store_order_id',
        'title', 'status', 'sale_total', 'purchase_total', 'notes',
    ];

    protected $casts = [
        'sale_total' => 'decimal:2',
        'purchase_total' => 'decimal:2',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(StoreClient::class, 'store_client_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StoreEstimateItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(StorePurchase::class);
    }

    public function recalculateTotals(): void
    {
        $items = $this->items()->get();
        $this->update([
            'sale_total' => $items->sum(fn (StoreEstimateItem $i) => (float) ($i->sale_price ?? 0) * (int) $i->qty),
            'purchase_total' => $items->sum(fn (StoreEstimateItem $i) => (float) ($i->supplier_price ?? 0) * (int) $i->qty),
        ]);
    }
}
