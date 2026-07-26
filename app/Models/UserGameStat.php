<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserGameStat extends Model
{
    protected $fillable = [
        'user_id',
        'game_id',
        'launch_count',
        'last_launched_at',
    ];

    protected $casts = [
        'launch_count' => 'integer',
        'last_launched_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
