<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PcThrone extends Model
{
    protected $fillable = [
        'club_id', 'computer_id', 'recorded_on', 'user_id', 'booking_id',
        'nickname', 'avatar', 'game', 'metric', 'kills', 'deaths', 'wins', 'losses', 'kd',
    ];

    protected $casts = [
        'recorded_on' => 'date',
        'kills' => 'integer',
        'deaths' => 'integer',
        'wins' => 'integer',
        'losses' => 'integer',
        'kd' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }
}
