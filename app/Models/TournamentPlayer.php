<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentPlayer extends Model
{
    protected $fillable = [
        'tournament_id', 'user_id', 'seed', 'computer_id', 'placement', 'prize_paid_minor',
    ];

    protected $casts = [
        'seed' => 'integer',
        'placement' => 'integer',
        'prize_paid_minor' => 'integer',
    ];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }
}
