<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAchievement extends Model
{
    protected $fillable = [
        'user_id',
        'achievement_id',
        'period_key',
        'progress',
        'completed_at',
        'rewarded_at',
    ];

    protected function casts(): array
    {
        return [
            'progress' => 'float',
            'completed_at' => 'datetime',
            'rewarded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function isRewarded(): bool
    {
        return $this->rewarded_at !== null;
    }
}
