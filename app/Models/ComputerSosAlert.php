<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComputerSosAlert extends Model
{
    public const REASON_PERIPHERALS = 'peripherals';
    public const REASON_AUTH_HELP = 'auth_help';
    public const REASON_OTHER = 'other';

    protected $fillable = [
        'computer_id',
        'booking_id',
        'reason_code',
        'reason_label',
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
