<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorePromoCode extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_USED = 'used';

    public const STATUS_EXPIRED = 'expired';

    public const SCOPE_PERIPHERAL = 'peripheral';

    protected $fillable = [
        'code',
        'user_id',
        'percent',
        'scope',
        'status',
        'lucky_seat_drop_id',
        'store_order_id',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'percent' => 'integer',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function drop(): BelongsTo
    {
        return $this->belongsTo(LuckySeatDrop::class, 'lucky_seat_drop_id');
    }

    public function storeOrder(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class);
    }

    public function isRedeemable(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        return ! $this->expires_at || $this->expires_at->isFuture();
    }
}
