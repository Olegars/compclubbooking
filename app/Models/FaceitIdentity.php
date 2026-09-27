<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaceitIdentity extends Model
{
    protected $fillable = [
        'user_id',
        'faceit_player_id',
        'nickname',
        'avatar_url',
        'steam_id_64',
        'skill_level',
        'elo',
        'game_id',
        'kd',
        'win_rate',
        'banned_until',
        'synced_at',
        'rate_limited_at',
    ];

    protected $casts = [
        'skill_level' => 'integer',
        'elo' => 'integer',
        'kd' => 'float',
        'win_rate' => 'float',
        'banned_until' => 'datetime',
        'synced_at' => 'datetime',
        'rate_limited_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isBanned(): bool
    {
        return $this->banned_until !== null && $this->banned_until->isFuture();
    }
}
