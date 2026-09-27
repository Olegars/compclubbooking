<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBattlePass extends Model
{
    protected $fillable = [
        'user_id', 'season_id', 'xp', 'level', 'claimed_level',
    ];

    protected function casts(): array
    {
        return [
            'xp' => 'integer',
            'level' => 'integer',
            'claimed_level' => 'integer',
        ];
    }
}
