<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoSurveillanceMarkerJob extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'club_id',
        'status',
        'title',
        'event',
        'channel',
        'track_id',
        'starts_at',
        'ends_at',
        'duration_sec',
        'pre_sec',
        'meta',
        'attempts',
        'claimed_at',
        'sent_at',
        'last_error',
    ];

    protected $casts = [
        'track_id' => 'integer',
        'duration_sec' => 'integer',
        'pre_sec' => 'integer',
        'attempts' => 'integer',
        'meta' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'claimed_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
