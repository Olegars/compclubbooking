<?php

namespace App\Services;

use App\Models\CalendarDayOverride;
use App\Models\Club;
use App\Models\DayGroup;
use App\Models\Tariff;
use App\Models\TariffPrice;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Цена = правило (зона + группа дней + окно времени) + доплата «+» комнаты.
 *
 * Бронь режется на сегменты по границам суток и тарифных окон
 * (как в калькуляторе Langame), ставка берётся на каждый сегмент отдельно.
 */
class TariffService
{
    public const DEFAULT_HOURLY_RUB = 250.0;

    /** @var array<string, Collection<int, TariffPrice>> */
    private array $rulesCache = [];

    /** @var array<string, int|null> */
    private array $dayGroupCache = [];

    /** @var Collection<string, CalendarDayOverride>|null */
    private ?Collection $overridesByDate = null;

    /** @var Collection<int, DayGroup>|null */
    private ?Collection $dayGroups = null;

    /**
     * Почасовая ставка зоны в момент startsAt (без доплаты комнаты).
     */
    public function hourlyRateRub(int $clubId, ?int $zoneId, ?CarbonImmutable $at = null): float
    {
        if (! $zoneId) {
            return self::DEFAULT_HOURLY_RUB;
        }

        $at ??= CarbonImmutable::now(config('app.timezone'));
        $rule = $this->matchRule($clubId, $zoneId, $at, hourlyOnly: true);

        return $rule ? (float) $rule->price : self::DEFAULT_HOURLY_RUB;
    }

    public function hasPricing(int $clubId, ?int $zoneId, ?CarbonImmutable $at = null): bool
    {
        if (! $zoneId) {
            return false;
        }

        $at ??= CarbonImmutable::now(config('app.timezone'));

        return $this->matchRule($clubId, $zoneId, $at, hourlyOnly: true) !== null
            || $this->rulesFor($clubId, $zoneId)->isNotEmpty();
    }

    /**
     * Сетка для UI: ставка и пакеты, доступные в момент startsAt.
     *
     * @return array<string, mixed>
     */
    public function gridForZone(
        int $clubId,
        ?int $zoneId,
        ?CarbonImmutable $startsAt = null,
        float $surchargePerHour = 0.0
    ): array {
        $at = $startsAt ?? CarbonImmutable::now(config('app.timezone'));
        $hourly = $this->hourlyRateRub($clubId, $zoneId, $at);
        $packages = [];

        if ($zoneId) {
            $packageTariffs = Tariff::query()
                ->where('is_active', true)
                ->where('threshold_hours', '>', 1)
                ->orderBy('threshold_hours')
                ->get()
                ->keyBy('id');

            foreach ($packageTariffs as $tariff) {
                $rule = $this->matchRule(
                    $clubId,
                    $zoneId,
                    $at,
                    tariffId: (int) $tariff->id
                );
                if (! $rule) {
                    continue;
                }

                $hours = (int) $tariff->threshold_hours;
                $base = (float) $rule->price;
                $withSurcharge = $base + ($surchargePerHour * $hours);

                $packages[] = [
                    'id' => (int) $tariff->id,
                    'title' => (string) $tariff->name,
                    'hours' => $hours,
                    'cost' => round($withSurcharge, 2),
                    'base_cost' => $base,
                    'hourly_equivalent' => round($withSurcharge / $hours, 2),
                    'finished_at' => $at->addMinutes($hours * 60)->toIso8601String(),
                ];
            }
        }

        $zone = $zoneId ? Zone::query()->find($zoneId) : null;
        $dayGroupId = $this->resolveDayGroupId($at);

        return [
            'zone_id' => $zoneId,
            'zone' => $zone?->name,
            'zone_slug' => $zone?->slug,
            'day_group_id' => $dayGroupId,
            'surcharge_per_hour' => round($surchargePerHour, 2),
            'hourly_rate' => round($hourly + $surchargePerHour, 2),
            'base_hourly_rate' => round($hourly, 2),
            'packages' => $packages,
        ];
    }

    /**
     * @param  'hourly'|'packages'  $mode
     * @return array<string, mixed>
     */
    public function priceForSeatRub(
        int $clubId,
        ?int $zoneId,
        float $hours,
        string $mode = 'hourly',
        ?int $tariffId = null,
        float $surchargePerHour = 0.0,
        ?CarbonImmutable $startsAt = null,
        ?CarbonImmutable $endsAt = null
    ): array {
        $hours = max(0.0, $hours);
        $surcharge = max(0.0, $surchargePerHour);
        $tz = config('app.timezone');

        if ($startsAt && $endsAt) {
            $start = $startsAt->timezone($tz);
            $end = $endsAt->timezone($tz);
        } else {
            $start = CarbonImmutable::now($tz)->startOfMinute();
            $end = $start->addMinutes((int) round($hours * 60));
        }

        if ($mode === 'packages') {
            return $this->packagePriceRub($clubId, $zoneId, $start, $end, $tariffId, $surcharge);
        }

        $segments = $this->segmentHourly($clubId, $zoneId, $start, $end);
        $base = round(array_sum(array_column($segments, 'cost_rub')), 2);
        $durationHours = $start->diffInMinutes($end) / 60;
        $extra = round($surcharge * $durationHours, 2);

        return [
            'mode' => 'hourly',
            'zone_id' => $zoneId,
            'hours' => $durationHours,
            'hourly_rate' => $segments[0]['rate'] ?? $this->hourlyRateRub($clubId, $zoneId, $start),
            'surcharge_per_hour' => $surcharge,
            'surcharge_rub' => $extra,
            'tariff_id' => null,
            'package_hours' => null,
            'package_price' => null,
            'extra_hours' => 0.0,
            'base_rub' => $base,
            'total_rub' => round($base + $extra, 2),
            'segments' => $segments,
        ];
    }

    /**
     * @param  array<int, array{zone_id: int|null, surcharge_per_hour?: float}>  $seats
     * @param  'hourly'|'packages'  $mode
     * @return array<string, mixed>
     */
    public function quoteSeats(
        int $clubId,
        array $seats,
        float $hours,
        string $mode = 'hourly',
        ?int $tariffId = null,
        ?CarbonImmutable $startsAt = null,
        ?CarbonImmutable $endsAt = null
    ): array {
        if ($seats === []) {
            throw ValidationException::withMessages([
                'pc_ids' => 'Выберите хотя бы одно место.',
            ]);
        }

        $zoneIds = array_values(array_unique(array_filter(array_map(
            fn ($seat) => $seat['zone_id'] ?? null,
            $seats
        ))));

        if ($mode === 'packages') {
            if (count($zoneIds) > 1) {
                throw ValidationException::withMessages([
                    'pc_ids' => 'Для пакетного тарифа все места должны быть одного типа помещения.',
                ]);
            }
            if (! $tariffId) {
                throw ValidationException::withMessages([
                    'tariff_id' => 'Выберите пакет.',
                ]);
            }
        }

        $perSeat = [];
        $total = 0.0;

        foreach ($seats as $computerId => $seat) {
            $line = $this->priceForSeatRub(
                $clubId,
                $seat['zone_id'] ?? null,
                $hours,
                $mode,
                $tariffId,
                (float) ($seat['surcharge_per_hour'] ?? 0),
                $startsAt,
                $endsAt
            );
            $line['computer_id'] = (int) $computerId;
            $perSeat[] = $line;
            $total += (float) $line['total_rub'];
        }

        $first = reset($seats);

        return [
            'mode' => $mode,
            'club_id' => $clubId,
            'zone_id' => $first['zone_id'] ?? null,
            'hours' => $hours,
            'tariff_id' => $tariffId,
            'total_rub' => round($total, 2),
            'total_minor' => (int) round($total * 100),
            'per_seat' => $perSeat,
        ];
    }

    /**
     * @return array{rates: list<array<string, mixed>>, packages: list<array<string, mixed>>}
     */
    public function showcase(?int $clubId = null): array
    {
        $clubId ??= (int) Club::query()->orderBy('id')->value('id');
        if (! $clubId) {
            return ['rates' => [], 'packages' => []];
        }

        $at = CarbonImmutable::now(config('app.timezone'));
        $zoneIds = TariffPrice::query()
            ->where('club_id', $clubId)
            ->distinct()
            ->pluck('zone_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $zones = Zone::query()
            ->whereIn('id', $zoneIds)
            ->orderBy('sort')
            ->orderBy('name')
            ->get();

        $rates = [];
        $packages = [];

        foreach ($zones as $zone) {
            $grid = $this->gridForZone($clubId, (int) $zone->id, $at);
            $hourly = (float) $grid['base_hourly_rate'];

            $rates[] = [
                'zone' => (string) $zone->name,
                'slug' => (string) $zone->slug,
                'specs' => [],
                'price' => (string) (int) round($hourly),
                'color' => (string) ($zone->color ?: '#22c55e'),
            ];

            foreach ($grid['packages'] as $package) {
                $full = $hourly * (int) $package['hours'];
                $discountPct = $full > 0
                    ? (int) round(max(0, (1 - ((float) $package['base_cost'] / $full)) * 100))
                    : 0;

                $packages[] = [
                    'id' => (int) $package['id'],
                    'name' => (string) $package['title'],
                    'category' => (string) $zone->slug,
                    'hours' => (int) $package['hours'],
                    'cost' => (float) $package['cost'],
                    'discount' => $discountPct > 0 ? "Скидка {$discountPct}%" : 'Пакет',
                ];
            }
        }

        usort($packages, fn (array $a, array $b) => $a['hours'] <=> $b['hours']);

        return ['rates' => $rates, 'packages' => $packages];
    }

    public function resolveDayGroupId(CarbonImmutable $at): ?int
    {
        $dateKey = $at->toDateString();
        if (array_key_exists($dateKey, $this->dayGroupCache)) {
            return $this->dayGroupCache[$dateKey];
        }

        $override = $this->overrides()->get($dateKey);
        if ($override) {
            return $this->dayGroupCache[$dateKey] = (int) $override->day_group_id;
        }

        $weekday = (int) $at->isoWeekday();
        foreach ($this->groups() as $group) {
            if ($group->includesWeekday($weekday)) {
                // «Все дни» (7 значений) — запасной; сначала более узкая группа.
                if (count($group->weekdays ?? []) === 7) {
                    continue;
                }

                return $this->dayGroupCache[$dateKey] = (int) $group->id;
            }
        }

        foreach ($this->groups() as $group) {
            if ($group->includesWeekday($weekday)) {
                return $this->dayGroupCache[$dateKey] = (int) $group->id;
            }
        }

        return $this->dayGroupCache[$dateKey] = null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function segmentHourly(
        int $clubId,
        ?int $zoneId,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        if (! $zoneId) {
            throw ValidationException::withMessages([
                'pc_ids' => 'Для выбранных мест не определён тип помещения.',
            ]);
        }

        if ($end <= $start) {
            return [];
        }

        $segments = [];
        $cursor = $start;

        while ($cursor < $end) {
            $rule = $this->matchRule($clubId, $zoneId, $cursor, hourlyOnly: true);
            if (! $rule) {
                throw ValidationException::withMessages([
                    'starts_at' => 'На '.$cursor->format('d.m H:i').' нет почасового тарифа для этой зоны.',
                ]);
            }

            $boundary = $this->nextBoundary($cursor, $end, $rule);
            $minutes = $cursor->diffInMinutes($boundary);
            if ($minutes <= 0) {
                break;
            }

            $rate = (float) $rule->price;
            $cost = round($rate * ($minutes / 60), 4);

            $segments[] = [
                'from' => $cursor->toIso8601String(),
                'to' => $boundary->toIso8601String(),
                'minutes' => $minutes,
                'rate' => $rate,
                'cost_rub' => round($cost, 2),
                'tariff_price_id' => (int) $rule->id,
                'day_group_id' => (int) $rule->day_group_id,
            ];

            $cursor = $boundary;
        }

        return $segments;
    }

    /**
     * @return array<string, mixed>
     */
    private function packagePriceRub(
        int $clubId,
        ?int $zoneId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $tariffId,
        float $surcharge
    ): array {
        if (! $zoneId || ! $tariffId) {
            throw ValidationException::withMessages([
                'tariff_id' => 'Пакет недоступен для выбранного типа помещения.',
            ]);
        }

        $tariff = Tariff::query()
            ->whereKey($tariffId)
            ->where('is_active', true)
            ->where('threshold_hours', '>', 1)
            ->first();

        if (! $tariff) {
            throw ValidationException::withMessages([
                'tariff_id' => 'Пакет не найден или отключён.',
            ]);
        }

        $rule = $this->matchRule($clubId, $zoneId, $start, tariffId: $tariffId);
        if (! $rule) {
            throw ValidationException::withMessages([
                'tariff_id' => 'Пакет недоступен в выбранное время.',
            ]);
        }

        $packageHours = (float) $tariff->threshold_hours;
        $durationHours = $start->diffInMinutes($end) / 60;

        if ($durationHours + 0.0001 < $packageHours) {
            throw ValidationException::withMessages([
                'duration' => "Для пакета «{$tariff->name}» нужно минимум {$packageHours} ч.",
            ]);
        }

        $extraHours = max(0.0, $durationHours - $packageHours);
        $packagePrice = (float) $rule->price;
        $extraCost = 0.0;

        if ($extraHours > 0) {
            $extraStart = $start->addMinutes((int) round($packageHours * 60));
            $extraSegments = $this->segmentHourly($clubId, $zoneId, $extraStart, $end);
            $extraCost = array_sum(array_column($extraSegments, 'cost_rub'));
        }

        $base = round($packagePrice + $extraCost, 2);
        $extra = round($surcharge * $durationHours, 2);
        $hourly = $this->hourlyRateRub($clubId, $zoneId, $start);

        return [
            'mode' => 'packages',
            'zone_id' => $zoneId,
            'hours' => $durationHours,
            'hourly_rate' => $hourly,
            'surcharge_per_hour' => $surcharge,
            'surcharge_rub' => $extra,
            'tariff_id' => (int) $tariff->id,
            'package_hours' => $packageHours,
            'package_price' => $packagePrice,
            'extra_hours' => $extraHours,
            'base_rub' => $base,
            'total_rub' => round($base + $extra, 2),
        ];
    }

    private function matchRule(
        int $clubId,
        int $zoneId,
        CarbonImmutable $at,
        bool $hourlyOnly = false,
        ?int $tariffId = null
    ): ?TariffPrice {
        $dayGroupId = $this->resolveDayGroupId($at);
        if (! $dayGroupId) {
            return null;
        }

        $minute = ((int) $at->format('H')) * 60 + (int) $at->format('i');
        $candidates = $this->rulesFor($clubId, $zoneId)
            ->filter(function (TariffPrice $rule) use ($dayGroupId, $minute, $hourlyOnly, $tariffId) {
                if ((int) $rule->day_group_id !== $dayGroupId) {
                    return false;
                }
                if ($tariffId && (int) $rule->tariff_id !== $tariffId) {
                    return false;
                }
                if ($hourlyOnly && (int) ($rule->tariff?->threshold_hours ?? 0) !== 1) {
                    return false;
                }
                if ($tariffId === null && ! $hourlyOnly && (int) ($rule->tariff?->threshold_hours ?? 0) <= 1) {
                    return false;
                }

                return $rule->coversMinute($minute);
            });

        if ($candidates->isEmpty()) {
            // Запасной вариант: правило группы «Все дни», если узкая группа не покрыта.
            $allDays = $this->groups()->first(fn (DayGroup $g) => count($g->weekdays ?? []) === 7);
            if ($allDays && (int) $allDays->id !== $dayGroupId) {
                $candidates = $this->rulesFor($clubId, $zoneId)
                    ->filter(function (TariffPrice $rule) use ($allDays, $minute, $hourlyOnly, $tariffId) {
                        if ((int) $rule->day_group_id !== (int) $allDays->id) {
                            return false;
                        }
                        if ($tariffId && (int) $rule->tariff_id !== $tariffId) {
                            return false;
                        }
                        if ($hourlyOnly && (int) ($rule->tariff?->threshold_hours ?? 0) !== 1) {
                            return false;
                        }

                        return $rule->coversMinute($minute);
                    });
            }
        }

        if ($candidates->isEmpty()) {
            return null;
        }

        // Самое узкое окно — самое специфичное правило.
        return $candidates->sortBy(function (TariffPrice $rule) {
            $span = (int) $rule->time_end - (int) $rule->time_start;
            if ($span <= 0) {
                $span += 1440;
            }

            return $span;
        })->first();
    }

    private function nextBoundary(CarbonImmutable $cursor, CarbonImmutable $end, TariffPrice $rule): CarbonImmutable
    {
        $candidates = [$end];

        $nextMidnight = $cursor->startOfDay()->addDay();
        if ($nextMidnight < $end) {
            $candidates[] = $nextMidnight;
        }

        $minute = ((int) $cursor->format('H')) * 60 + (int) $cursor->format('i');
        $start = (int) $rule->time_start;
        $ruleEnd = (int) $rule->time_end;

        if ($start < $ruleEnd) {
            // Обычное окно: граница — time_end сегодня.
            $boundary = $cursor->startOfDay()->addMinutes($ruleEnd);
            if ($boundary > $cursor && $boundary < $end) {
                $candidates[] = $boundary;
            }
        } else {
            // Через полночь: если сейчас после start — граница полночь или time_end завтра.
            if ($minute >= $start) {
                if ($nextMidnight > $cursor && $nextMidnight < $end) {
                    $candidates[] = $nextMidnight;
                }
            } else {
                $boundary = $cursor->startOfDay()->addMinutes($ruleEnd);
                if ($boundary > $cursor && $boundary < $end) {
                    $candidates[] = $boundary;
                }
            }
        }

        $next = $end;
        foreach ($candidates as $candidate) {
            if ($candidate > $cursor && $candidate <= $next) {
                $next = $candidate;
            }
        }

        // Защита от зацикливания: минимум +1 минута.
        if ($next <= $cursor) {
            $next = $cursor->addMinute();
        }

        return $next;
    }

    /**
     * @return Collection<int, TariffPrice>
     */
    private function rulesFor(int $clubId, int $zoneId): Collection
    {
        $key = $clubId.':'.$zoneId;
        if (isset($this->rulesCache[$key])) {
            return $this->rulesCache[$key];
        }

        return $this->rulesCache[$key] = TariffPrice::query()
            ->with('tariff')
            ->where('club_id', $clubId)
            ->where('zone_id', $zoneId)
            ->whereHas('tariff', fn ($q) => $q->where('is_active', true))
            ->get();
    }

    /**
     * @return Collection<int, DayGroup>
     */
    private function groups(): Collection
    {
        return $this->dayGroups ??= DayGroup::query()
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    /**
     * @return Collection<string, CalendarDayOverride>
     */
    private function overrides(): Collection
    {
        return $this->overridesByDate ??= CalendarDayOverride::query()
            ->get()
            ->keyBy(fn (CalendarDayOverride $row) => $row->date->toDateString());
    }
}
