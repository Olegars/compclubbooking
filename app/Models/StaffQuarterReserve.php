<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffQuarterReserve extends Model
{
    protected $fillable = [
        'admin_id',
        'year',
        'quarter',
        'points',
        'burned_at',
    ];

    protected $casts = [
        'points' => 'decimal:2',
        'burned_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
