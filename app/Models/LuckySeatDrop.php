<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LuckySeatDrop extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_OPENED = 'opened';

    public const STATUS_EXPIRED = 'expired';

    public const TRIGGER_WIN_STREAK = 'win_streak';

    public const TRIGGER_PLAYTIME = 'playtime';

    public const REWARD_BONUS = 'bonus';

    public const REWARD_DRINK = 'drink';

    public const REWARD_STORE_PROMO = 'store_promo';

    protected $fillable = [
        'user_id',
        'booking_id',
        'computer_id',
        'club_id',
        'trigger',
        'status',
        'reward_type',
        'reward',
        'opened_at',
        'expires_at',
    ];

    protected $casts = [
        'reward' => 'array',
        'opened_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    public function storePromo(): HasOne
    {
        return $this->hasOne(StorePromoCode::class);
    }

    public function isPending(): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
