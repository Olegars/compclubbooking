<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DayGroup extends Model
{
    protected $fillable = [
        'name',
        'color',
        'weekdays',
        'sort',
    ];

    protected $casts = [
        'weekdays' => 'array',
        'sort' => 'integer',
    ];

    public function prices(): HasMany
    {
        return $this->hasMany(TariffPrice::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(CalendarDayOverride::class);
    }

    public function includesWeekday(int $isoWeekday): bool
    {
        return in_array($isoWeekday, array_map('intval', $this->weekdays ?? []), true);
    }
}
