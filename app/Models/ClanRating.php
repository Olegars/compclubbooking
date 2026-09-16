<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClanRating extends Model
{
    protected $fillable = [
        'kind',
        'club_id',
        'faction_key',
        'name',
        'rating',
        'wars_played',
        'wars_won',
        'wars_drawn',
        'points_total',
    ];

    protected $casts = [
        'club_id' => 'integer',
        'rating' => 'integer',
        'wars_played' => 'integer',
        'wars_won' => 'integer',
        'wars_drawn' => 'integer',
        'points_total' => 'integer',
    ];

    public function playerRatings(): HasMany
    {
        return $this->hasMany(ClanPlayerRating::class);
    }
}
