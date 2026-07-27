<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingGroup extends Model
{
    protected $fillable = [
        'user_id',
        'club_id',
        'starts_at',
        'ends_at',
        'status',
        'payment_status',
        'currency',
        'computers_total_minor',
        'games_total_minor',
        'total_minor',
        'paid_total_minor',
        'refunded_total_minor',
        'pricing_snapshot',
        'paid_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'pricing_snapshot' => 'array',
            'computers_total_minor' => 'integer',
            'games_total_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_total_minor' => 'integer',
            'refunded_total_minor' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function games(): HasMany
    {
        return $this->hasMany(BookingGame::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
