<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComputerThermal extends Model
{
    protected $fillable = [
        'club_id',
        'computer_id',
        'cpu_c',
        'is_hot',
        'reported_at',
    ];

    protected $casts = [
        'club_id' => 'integer',
        'computer_id' => 'integer',
        'cpu_c' => 'float',
        'is_hot' => 'boolean',
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
