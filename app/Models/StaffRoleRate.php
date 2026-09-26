<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffRoleRate extends Model
{
    protected $fillable = [
        'role',
        'shift_rate',
    ];

    protected $casts = [
        'shift_rate' => 'decimal:2',
    ];
}
