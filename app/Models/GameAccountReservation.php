<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameAccountReservation extends Model
{
    protected $fillable = [
        'booking_game_id',
        'booking_id',
        'game_account_id',
        'starts_at',
        'ends_at',
        'status',
        'activated_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    public function bookingGame(): BelongsTo
    {
        return $this->belongsTo(BookingGame::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GameAccount::class, 'game_account_id');
    }
}
