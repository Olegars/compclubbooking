<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirePanel extends Model
{
    protected $fillable = [
        'club_id',
        'host',
        'port',
        'panel_address',
        'enabled',
        'reset_pending',
        'fail_count',
        'last_ok_at',
        'last_reset_at',
        'last_error',
    ];

    protected $casts = [
        'port' => 'integer',
        'panel_address' => 'integer',
        'enabled' => 'boolean',
        'reset_pending' => 'boolean',
        'fail_count' => 'integer',
        'last_ok_at' => 'datetime',
        'last_reset_at' => 'datetime',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
