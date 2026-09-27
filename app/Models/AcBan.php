<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcBan extends Model
{
    public const SCOPE_MATCH_MAKING = 'match_making';

    public const SCOPE_TOURNAMENT = 'tournament';

    public const SCOPE_FULL = 'full_ban';

    /** @var list<string> */
    public const SCOPES = [
        self::SCOPE_MATCH_MAKING,
        self::SCOPE_TOURNAMENT,
        self::SCOPE_FULL,
    ];

    protected $fillable = [
        'user_id', 'hwid_digest', 'scope', 'reason', 'created_by',
        'starts_at', 'ends_at', 'pardoned_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'pardoned_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
