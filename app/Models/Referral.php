<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    protected $fillable = [
        'referrer_user_id',
        'referee_user_id',
        'promo_code',
        'promo_percent',
        'promo_expires_at',
        'promo_used_at',
        'promo_booking_group_id',
        'first_paid_at',
        'first_booking_group_id',
        'reward_booking_group_id',
        'rewarded_at',
        'reward_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'promo_percent' => 'integer',
            'promo_expires_at' => 'datetime',
            'promo_used_at' => 'datetime',
            'first_paid_at' => 'datetime',
            'rewarded_at' => 'datetime',
            'reward_snapshot' => 'array',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referee_user_id');
    }
}
