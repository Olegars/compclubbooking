<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WledCue extends Model
{
    protected $fillable = [
        'club_id',
        'wled_controller_id',
        'event_id',
        'priority',
        'payload',
        'expires_at',
        'claimed_by_computer_id',
        'claimed_at',
        'played_at',
        'last_error',
    ];

    protected $casts = [
        'club_id' => 'integer',
        'wled_controller_id' => 'integer',
        'priority' => 'integer',
        'payload' => 'array',
        'expires_at' => 'datetime',
        'claimed_by_computer_id' => 'integer',
        'claimed_at' => 'datetime',
        'played_at' => 'datetime',
    ];

    public function controller(): BelongsTo
    {
        return $this->belongsTo(WledController::class, 'wled_controller_id');
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
