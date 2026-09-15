<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LanBountyEvent extends Model
{
    protected $fillable = [
        'club_id',
        'computer_id',
        'user_id',
        'booking_id',
        'steam_id',
        'event',
        'game',
        'weapon',
        'map',
        'match_id',
        'round',
        'occurred_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'payload' => 'array',
            'round' => 'integer',
        ];
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }
}
