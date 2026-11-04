<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HostMemorySample extends Model
{
    protected $fillable = [
        'hostname',
        'load_pct',
        'total_mb',
        'used_mb',
        'available_mb',
        'reported_at',
    ];

    protected $casts = [
        'load_pct' => 'integer',
        'total_mb' => 'integer',
        'used_mb' => 'integer',
        'available_mb' => 'integer',
        'reported_at' => 'datetime',
    ];
}
