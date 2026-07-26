<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComputerInputAlert extends Model
{
    public const TYPE_DEVICE_CHANGED = 'device_changed';
    public const TYPE_DISCONNECTED = 'disconnected';
    public const TYPE_UNSTABLE = 'unstable';

    protected $fillable = [
        'computer_id',
        'booking_id',
        'type',
        'severity',
        'payload',
        'resolved_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
