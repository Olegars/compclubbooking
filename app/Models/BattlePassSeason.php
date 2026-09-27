<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BattlePassSeason extends Model
{
    public const LIVE = 'live';

    public const XP_CLOSED = 'xp_closed';

    public const ARCHIVED = 'archived';

    protected $fillable = [
        'club_id', 'title', 'starts_on', 'ends_on', 'status', 'is_active', 'claim_grace_days', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
            'claim_grace_days' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    public function levels(): HasMany
    {
        return $this->hasMany(BattlePassLevel::class, 'season_id')->orderBy('level');
    }
}
