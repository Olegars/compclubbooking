<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentMatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_DONE = 'done';

    protected $fillable = [
        'tournament_id', 'round', 'slot',
        'player1_id', 'player2_id', 'winner_id',
        'score1', 'score2', 'status',
    ];

    protected $casts = [
        'round' => 'integer',
        'slot' => 'integer',
        'score1' => 'integer',
        'score2' => 'integer',
    ];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function player1(): BelongsTo
    {
        return $this->belongsTo(TournamentPlayer::class, 'player1_id');
    }

    public function player2(): BelongsTo
    {
        return $this->belongsTo(TournamentPlayer::class, 'player2_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(TournamentPlayer::class, 'winner_id');
    }
}
