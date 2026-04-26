<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shift extends Model
{
    protected $fillable = [
        'admin_id',
        'closed_by',
        'started_at',
        'ended_at',
        'cash_start',
        'cash_end',
        'status'
    ];

    // Связь с админом, который открыл смену
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    // Связь с записями инвентаризации этой смены
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(ShiftInventory::class);
    }
}
