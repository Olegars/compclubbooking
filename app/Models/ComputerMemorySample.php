<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComputerMemorySample extends Model
{
    protected $fillable = [
        'club_id',
        'computer_id',
        'load_pct',
        'total_mb',
        'used_mb',
        'available_mb',
        'reported_at',
    ];

    protected $casts = [
        'club_id' => 'integer',
        'computer_id' => 'integer',
        'load_pct' => 'integer',
        'total_mb' => 'integer',
        'used_mb' => 'integer',
        'available_mb' => 'integer',
        'reported_at' => 'datetime',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }
}
