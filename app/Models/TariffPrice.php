<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Цена тарифа в клубе для зоны в окне день+время.
 * Доплата «+» комнаты живёт на Space.
 */
class TariffPrice extends Model
{
    protected $fillable = [
        'tariff_id',
        'club_id',
        'zone_id',
        'day_group_id',
        'time_start',
        'time_end',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'time_start' => 'integer',
        'time_end' => 'integer',
    ];

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function dayGroup(): BelongsTo
    {
        return $this->belongsTo(DayGroup::class);
    }

    /**
     * Попадает ли минута суток в интервал правила.
     * time_end < time_start означает переход через полночь.
     */
    public function coversMinute(int $minuteOfDay): bool
    {
        $start = (int) $this->time_start;
        $end = (int) $this->time_end;

        if ($start === $end) {
            return false;
        }

        if ($start < $end) {
            return $minuteOfDay >= $start && $minuteOfDay < $end;
        }

        // Через полночь: 22:00–02:00
        return $minuteOfDay >= $start || $minuteOfDay < $end;
    }
}
