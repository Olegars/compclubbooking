<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftIntern extends Model
{
    protected $fillable = [
        'shift_id',
        'admin_id',
        'joined_at',
        'left_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
