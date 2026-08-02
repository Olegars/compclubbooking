<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'user_id',
        'booking_group_id',
        'amount',
        'type',
        'source',
        'description',
        'payload',
        'idempotency_key',
        'receipt_id',
        'fiscal_mode',
        'fiscal_status',
        'fiscal_receipt_url',
        'fiscal_error',
        'fiscal_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'amount' => 'float',
        'fiscal_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookingGroup(): BelongsTo
    {
        return $this->belongsTo(BookingGroup::class);
    }
}
