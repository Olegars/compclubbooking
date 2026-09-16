<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubFeature extends Model
{
    protected $fillable = [
        'club_id',
        'key',
        'enabled',
        'settings',
    ];

    protected $casts = [
        'club_id' => 'integer',
        'enabled' => 'boolean',
        'settings' => 'array',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
