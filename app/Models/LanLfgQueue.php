<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LanLfgQueue extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_SEATED = 'seated';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'club_id', 'user_id', 'booking_id', 'computer_id',
        'game', 'rank', 'rank_tier', 'status',
        'matched_user_id', 'matched_computer_id', 'matched_queue_id',
        'matched_at', 'expires_at',
    ];

    protected $casts = [
        'rank_tier' => 'integer',
        'matched_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    public function matchedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_user_id');
    }

    public function matchedComputer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'matched_computer_id');
    }
}
