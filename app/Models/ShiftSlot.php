<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftSlot extends Model
{
    protected $fillable = [
        'club_id',
        'template_id',
        'starts_at',
        'ends_at',
        'intern_capacity',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'intern_capacity' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftSlotTemplate::class, 'template_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(ShiftSlotBooking::class);
    }

    public function activeBookings(): HasMany
    {
        return $this->hasMany(ShiftSlotBooking::class)->where('status', ShiftSlotBooking::STATUS_BOOKED);
    }
}
