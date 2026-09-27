<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaceitMatchPlayer extends Model
{
    protected $fillable = [
        'faceit_match_id',
        'faceit_player_id',
        'user_id',
        'faction',
        'elo_before',
        'elo_after',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FaceitMatch::class, 'faceit_match_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
