<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreAssemblyClipJob extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'club_id',
        'store_built_pc_id',
        'status',
        'channel',
        'track_id',
        'starts_at',
        'ends_at',
        'attempts',
        'claimed_at',
        'sent_at',
        'last_error',
        'meta',
    ];

    protected $casts = [
        'track_id' => 'integer',
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

    public function builtPc(): BelongsTo
    {
        return $this->belongsTo(StoreBuiltPc::class, 'store_built_pc_id');
    }
}
