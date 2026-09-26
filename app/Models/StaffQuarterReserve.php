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
    ];

    protected $casts = [
        'points' => 'decimal:2',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
