<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserRewardGrant extends Model
{
    protected $fillable = [
        'user_id', 'season_id', 'level_id', 'tier_id', 'reward_kind', 'payload',
        'period_key', 'granted_at', 'consumed_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'granted_at' => 'datetime',
            'consumed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
