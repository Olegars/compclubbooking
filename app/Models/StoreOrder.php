<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StoreOrder extends Model
{
    public const STATUSES = ['new', 'assembling', 'ready', 'issued', 'cancelled', 'returned'];

    protected $fillable = [
        'club_id', 'store_client_id', 'assignee_id', 'status', 'total', 'notes',
    ];

    protected $casts = [
        'total' => 'decimal:2',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(StoreClient::class, 'store_client_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assignee_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StoreOrderItem::class);
    }

    public function builtPc(): HasOne
    {
        return $this->hasOne(StoreBuiltPc::class, 'store_order_id');
    }

    public function warranties(): HasMany
    {
        return $this->hasMany(StoreWarranty::class);
    }
}
