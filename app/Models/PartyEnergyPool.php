<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartyEnergyPool extends Model
{
    protected $fillable = [
        'booking_group_id',
        'club_id',
        'captain_user_id',
        'minutes_remaining',
        'auto_fuel',
        'last_siphon_at',
    ];

    protected function casts(): array
    {
        return [
            'minutes_remaining' => 'integer',
            'auto_fuel' => 'boolean',
            'last_siphon_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(BookingGroup::class, 'booking_group_id');
    }

    public function captain(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captain_user_id');
    }
}
