<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArenaDuelParticipant extends Model
{
    public const ESCROW_HELD = 'held';

    public const ESCROW_REFUNDED = 'refunded';

    public const ESCROW_SETTLED = 'settled';

    public const ESCROW_FORFEIT = 'forfeit';

    protected $fillable = [
        'duel_id',
        'user_id',
        'computer_id',
        'booking_id',
        'team_slot',
        'escrow_status',
        'rounds_won',
        'last_gsi_at',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'team_slot' => 'integer',
            'rounds_won' => 'integer',
            'last_gsi_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    public function duel(): BelongsTo
    {
        return $this->belongsTo(ArenaDuel::class, 'duel_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }
}
