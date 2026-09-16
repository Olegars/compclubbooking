<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoldenImageRevision extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'club_id', 'computer_id', 'status', 'disk_mode',
        'aggregate_hash', 'inventory_hash',
        'steam_count', 'epic_count', 'file_count', 'changed_count',
        'files', 'changed_rels', 'note',
        'verified_by', 'verified_at', 'rolled_back_at',
    ];

    protected $casts = [
        'files' => 'array',
        'changed_rels' => 'array',
        'verified_at' => 'immutable_datetime',
        'rolled_back_at' => 'immutable_datetime',
        'steam_count' => 'integer',
        'epic_count' => 'integer',
        'file_count' => 'integer',
        'changed_count' => 'integer',
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
