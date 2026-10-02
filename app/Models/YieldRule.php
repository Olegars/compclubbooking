<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Динамическая поправка почасовой ставки: окно дней/времени и порог загрузки зоны.
 */
class YieldRule extends Model
{
    protected $fillable = [
        'club_id',
        'zone_id',
        'name',
        'kind',
        'is_active',
        'weekdays',
        'time_start',
        'time_end',
        'utilization_op',
        'utilization_percent',
        'adjust_percent',
        'priority',
    ];

    protected $casts = [
        'weekdays' => 'array',
        'is_active' => 'boolean',
        'time_start' => 'integer',
        'time_end' => 'integer',
        'utilization_percent' => 'integer',
        'adjust_percent' => 'decimal:2',
        'priority' => 'integer',
    ];

    /**
     * Типовые правила: пустой зал в будни и высокий спрос в пятницу вечером.
     *
     * @return list<array<string, mixed>>
     */
    public static function presets(): array
    {
        return [
            [
                'name' => 'Счастливый час',
                'kind' => 'discount',
                'weekdays' => [1, 2, 3, 4, 5],
                'time_start' => 10 * 60,
                'time_end' => 17 * 60,
                'utilization_op' => 'below',
                'utilization_percent' => 20,
                'adjust_percent' => -25,
                'priority' => 10,
            ],
            [
                'name' => 'Пятничный спрос',
                'kind' => 'surge',
                'weekdays' => [5],
                'time_start' => 18 * 60,
                'time_end' => 1440,
                'utilization_op' => 'above',
                'utilization_percent' => 70,
                'adjust_percent' => 20,
                'priority' => 20,
            ],
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function covers(CarbonImmutable $at): bool
    {
        $days = array_map('intval', $this->weekdays ?? []);
        if (! in_array((int) $at->isoWeekday(), $days, true)) {
            return false;
        }

        $minute = ((int) $at->format('H')) * 60 + (int) $at->format('i');

        return $this->coversMinute($minute);
    }

    public function matchesUtilization(float $percent): bool
    {
        $threshold = (float) $this->utilization_percent;

        return $this->utilization_op === 'below'
            ? $percent < $threshold
            : $percent >= $threshold;
    }

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

        return $minuteOfDay >= $start || $minuteOfDay < $end;
    }
}
