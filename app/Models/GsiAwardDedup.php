<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GsiAwardDedup extends Model
{
    public $timestamps = false;

    protected $table = 'gsi_award_dedup';

    protected $fillable = ['user_id', 'achievement_code', 'match_round_key', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
