<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftSlotTemplate extends Model
{
    protected $fillable = [
        'club_id',
        'name',
        'starts_time',
        'duration_hours',
        'intern_capacity',
        'is_active',
    ];

    protected $casts = [
        'duration_hours' => 'integer',
        'intern_capacity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function slots(): HasMany
    {
        return $this->hasMany(ShiftSlot::class, 'template_id');
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
