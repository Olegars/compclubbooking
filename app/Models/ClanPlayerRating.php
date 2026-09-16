<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClanPlayerRating extends Model
{
    protected $fillable = [
        'user_id',
        'clan_rating_id',
        'rating',
        'wars_played',
        'points_contributed',
        'last_war_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'clan_rating_id' => 'integer',
        'rating' => 'integer',
        'wars_played' => 'integer',
        'points_contributed' => 'integer',
        'last_war_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clan(): BelongsTo
    {
        return $this->belongsTo(ClanRating::class, 'clan_rating_id');
    }
}
