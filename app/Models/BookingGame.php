<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingGame extends Model
{
    protected $fillable = [
        'booking_group_id',
        'club_game_id',
        'quantity',
        'game_title',
        'platform',
        'billing_mode',
        'unit_price_minor',
        'billing_unit_minutes',
        'line_total_minor',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'billing_unit_minutes' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(BookingGroup::class, 'booking_group_id');
    }

    public function clubGame(): BelongsTo
    {
        return $this->belongsTo(ClubGame::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(GameAccountReservation::class);
    }
}
