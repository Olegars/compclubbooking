<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentClipJob extends Model
{
    public const SUBJECT_INCIDENT = 'incident';

    public const SUBJECT_SOS = 'sos';

    public const SUBJECT_HID = 'hid';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'club_id',
        'subject_type',
        'subject_id',
        'computer_id',
        'status',
        'channel',
        'track_id',
        'event_at',
        'starts_at',
        'ends_at',
        'file_name',
        'file_path',
        'bytes',
        'attempts',
        'claimed_at',
        'sent_at',
        'last_error',
    ];

    protected $casts = [
        'subject_id' => 'integer',
        'computer_id' => 'integer',
        'track_id' => 'integer',
        'bytes' => 'integer',
        'attempts' => 'integer',
        'event_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'claimed_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }
}
