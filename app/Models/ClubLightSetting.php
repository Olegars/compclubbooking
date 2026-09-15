<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubLightSetting extends Model
{
    protected $fillable = [
        'club_id',
        'events',
    ];

    protected $casts = [
        'club_id' => 'integer',
        'events' => 'array',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
