<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftSlotBooking extends Model
{
    public const KIND_LEAD = 'lead';

    public const KIND_INTERN = 'intern';

    public const STATUS_BOOKED = 'booked';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'shift_slot_id',
        'admin_id',
        'kind',
        'status',
        'cancelled_at',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
    ];

    public function slot(): BelongsTo
    {
        return $this->belongsTo(ShiftSlot::class, 'shift_slot_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
