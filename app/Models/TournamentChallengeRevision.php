<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentChallengeRevision extends Model
{
    protected $fillable = [
        'challenge_id',
        'club_id',
        'admin_id',
        'action',
        'terms',
        'comment',
    ];

    protected $casts = [
        'terms' => 'array',
    ];

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(TournamentChallenge::class, 'challenge_id');
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
