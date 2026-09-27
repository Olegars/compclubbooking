<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BattlePassClaim extends Model
{
    protected $fillable = ['user_id', 'level_id', 'claimed_at'];

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime'];
    }
}
