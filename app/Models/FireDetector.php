<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FireDetector extends Model
{
    protected $fillable = [
        'club_id',
        'space_id',
        'device_address',
        'loop_address',
        'smoke',
        'last_code',
        'last_seen_at',
    ];

    protected $casts = [
        'device_address' => 'integer',
        'loop_address' => 'integer',
        'smoke' => 'boolean',
        'last_code' => 'integer',
        'last_seen_at' => 'datetime',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }
}
