<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArenaRating extends Model
{
    public const BASE = 1000;

    protected $fillable = [
        'club_id',
        'user_id',
        'rating',
        'wins',
        'losses',
        'streak',
        'best_streak',
        'week_wins',
        'week_start',
        'evening_date',
        'evening_streak',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'wins' => 'integer',
            'losses' => 'integer',
            'streak' => 'integer',
            'best_streak' => 'integer',
            'week_wins' => 'integer',
            'week_start' => 'date',
            'evening_date' => 'date',
            'evening_streak' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
