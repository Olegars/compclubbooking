<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreWarranty extends Model
{
    public const STATUSES = ['active', 'claimed', 'closed'];

    protected $fillable = [
        'club_id', 'store_client_id', 'store_order_id', 'store_order_item_id',
        'serial', 'product_name', 'started_at', 'ends_at', 'status', 'claim_notes',
    ];

    protected $casts = [
        'started_at' => 'date',
        'ends_at' => 'date',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(StoreClient::class, 'store_client_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(StoreOrderItem::class, 'store_order_item_id');
    }
}
