<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FireAlarm extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLEARED = 'cleared';

    protected $fillable = [
        'club_id',
        'space_id',
        'detector_id',
        'status',
        'started_at',
        'cleared_at',
        'last_code',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'cleared_at' => 'datetime',
        'last_code' => 'integer',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function detector(): BelongsTo
    {
        return $this->belongsTo(FireDetector::class, 'detector_id');
    }
}
