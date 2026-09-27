<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FaceitMatch extends Model
{
    protected $fillable = [
        'match_id',
        'hub_id',
        'championship_id',
        'status',
        'map',
        'started_at',
        'finished_at',
        'payload',
        'demo_url',
    ];

    protected $casts = [
        'payload' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function players(): HasMany
    {
        return $this->hasMany(FaceitMatchPlayer::class);
    }
}
