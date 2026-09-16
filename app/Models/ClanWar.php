<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClanWar extends Model
{
    public const MODE_ZONE = 'zone';

    public const MODE_LOCATION = 'location';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_LIVE = 'live';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'host_club_id',
        'name',
        'mode',
        'game',
        'status',
        'starts_at',
        'ends_at',
        'duration_minutes',
        'side_a_type',
        'side_a_key',
        'side_a_label',
        'side_a_club_id',
        'side_b_type',
        'side_b_key',
        'side_b_label',
        'side_b_club_id',
        'score_a',
        'score_b',
        'wins_a',
        'wins_b',
        'rounds_a',
        'rounds_b',
        'winner_side',
        'rating_applied_at',
    ];

    protected $casts = [
        'host_club_id' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'duration_minutes' => 'integer',
        'side_a_club_id' => 'integer',
        'side_b_club_id' => 'integer',
        'score_a' => 'integer',
        'score_b' => 'integer',
        'wins_a' => 'integer',
        'wins_b' => 'integer',
        'rounds_a' => 'integer',
        'rounds_b' => 'integer',
        'rating_applied_at' => 'datetime',
    ];

    public function hostClub(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'host_club_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ClanWarEvent::class);
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }
}
