<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameAccount extends Model
{
    protected $fillable = [
        'game_id',
        'login',
        'password',
        'status',
        'current_pc_id',
        'shared_secret',
        'persona_name',
        'steam_id',
        'refresh_token',
        'refresh_token_updated_at',
    ];

    protected $hidden = [
        'password',
        'shared_secret',
        'refresh_token',
    ];

    protected $casts = [
        'refresh_token_updated_at' => 'datetime',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function machineCaches(): HasMany
    {
        return $this->hasMany(GameAccountMachineCache::class, 'game_account_id');
    }

    public function cacheForComputer(int $computerId): ?GameAccountMachineCache
    {
        return $this->machineCaches()->where('computer_id', $computerId)->first();
    }
}
