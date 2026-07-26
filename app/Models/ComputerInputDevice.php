<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComputerInputDevice extends Model
{
    protected $fillable = [
        'computer_id',
        'booking_id',
        'fingerprint',
        'bound_at',
    ];

    protected $casts = [
        'fingerprint' => 'array',
        'bound_at' => 'datetime',
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
