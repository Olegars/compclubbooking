<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarDayOverride extends Model
{
    protected $fillable = [
        'date',
        'day_group_id',
        'note',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function dayGroup(): BelongsTo
    {
        return $this->belongsTo(DayGroup::class);
    }
}
