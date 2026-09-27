<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BattlePassLevel extends Model
{
    protected $fillable = [
        'season_id', 'level', 'xp_required', 'reward_kind', 'reward_payload',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'xp_required' => 'integer',
            'reward_payload' => 'array',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(BattlePassSeason::class, 'season_id');
    }
}
