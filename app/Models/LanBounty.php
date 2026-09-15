<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LanBounty extends Model
{
    public const KIND_FRAG = 'frag';

    public const KIND_DUEL = 'duel';

    public const STAKE_DEPOSIT = 'deposit';

    public const STAKE_PRODUCT = 'product';

    public const STATUS_OPEN = 'open';

    public const STATUS_WON = 'won';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'club_id',
        'poster_user_id',
        'poster_computer_id',
        'poster_booking_id',
        'target_computer_id',
        'hunter_user_id',
        'hunter_computer_id',
        'kind',
        'game',
        'weapon',
        'title',
        'stake_type',
        'stake_amount',
        'product_id',
        'product_name',
        'status',
        'expires_at',
        'settled_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'stake_amount' => 'float',
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'poster_user_id');
    }

    public function targetComputer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'target_computer_id');
    }

    public function hunterComputer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'hunter_computer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
