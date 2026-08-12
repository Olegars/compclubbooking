<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorePurchase extends Model
{
    public const STATUSES = ['draft', 'submitted', 'confirmed', 'received', 'cancelled'];

    public const STATUS_LABELS = [
        'draft' => 'Черновик',
        'submitted' => 'Отправлен',
        'confirmed' => 'Подтверждён',
        'received' => 'Принят',
        'cancelled' => 'Отменён',
    ];

    protected $fillable = [
        'club_id', 'store_estimate_id', 'created_by', 'external_order_id',
        'status', 'total', 'notes', 'submitted_at', 'received_at',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'submitted_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(StoreEstimate::class, 'store_estimate_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StorePurchaseItem::class);
    }
}
