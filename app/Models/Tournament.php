<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tournament extends Model
{
    protected $fillable = [
        'club_id',
        'name',
        'game_id',
        'start_at',
        'end_at',
        'entry_fee',
        'prize_pool',
        'prize_first_minor',
        'prize_second_minor',
        'prize_third_minor',
        'prizes_paid_at',
        'status',
        'format',
        'lock_games',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'prizes_paid_at' => 'datetime',
        'lock_games' => 'boolean',
        'entry_fee' => 'float',
        'prize_first_minor' => 'integer',
        'prize_second_minor' => 'integer',
        'prize_third_minor' => 'integer',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function computers()
    {
        return $this->belongsToMany(Computer::class, 'tournament_computer');
    }

    public function players(): HasMany
    {
        return $this->hasMany(TournamentPlayer::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class);
    }
}
