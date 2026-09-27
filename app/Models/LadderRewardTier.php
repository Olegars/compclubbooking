<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LadderRewardTier extends Model
{
    protected $fillable = [
        'club_id', 'metric', 'threshold', 'reward_kind', 'payload', 'period', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
            'payload' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
