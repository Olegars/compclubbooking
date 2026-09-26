<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffBonusSetting extends Model
{
    protected $fillable = [
        'xp_to_rub_rate',
        'bar_target_rub',
    ];

    protected $casts = [
        'xp_to_rub_rate' => 'decimal:2',
        'bar_target_rub' => 'decimal:2',
    ];
}
