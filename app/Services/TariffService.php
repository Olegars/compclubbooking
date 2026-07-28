<?php

namespace App\Services;

use App\Models\Tariff;
use App\Models\Zone;
use Illuminate\Validation\ValidationException;

class TariffService
{
    public const DEFAULT_HOURLY_RUB = 250.0;

    /**
     * Почасовая ставка зоны (₽/ч): тариф threshold=1 или минимальный порог.
     */
    public function hourlyRateRub(string $category): float
    {
        $category = strtolower(trim($category)) ?: MapZoneResolver::FALLBACK_CATEGORY;

        $hourly = Tariff::query()
            ->where('is_active', true)
            ->where('category', $category)
            ->where('threshold_hours', 1)
            ->orderBy('id')
            ->first();

        if ($hourly) {
            return (float) $hourly->price_per_package;
        }

        $smallest = Tariff::query()
            ->where('is_active', true)
            ->where('category', $category)
            ->orderBy('threshold_hours')
            ->first();

        if ($smallest && (int) $smallest->threshold_hours > 0) {
            return (float) $smallest->price_per_package / (int) $smallest->threshold_hours;
        }

        // Fallback без категории — глобальный 1ч
        $global = Tariff::query()
            ->where('is_active', true)
            ->where('threshold_hours', 1)
            ->orderBy('id')
            ->first();

        if ($global) {
            return (float) $global->price_per_package;
        }

        return self::DEFAULT_HOURLY_RUB;
    }

    /**
     * Сетка тарифов зоны для UI (почасовой + пакеты > 1ч).
     *
     * @return array{category: string, hourly_rate: float, packages: list<array<string, mixed>>}
     */
    public function gridForCategory(string $category, ?\Carbon\CarbonImmutable $startsAt = null): array
    {
        $category = strtolower(trim($category)) ?: MapZoneResolver::FALLBACK_CATEGORY;
        $hourly = $this->hourlyRateRub($category);

        $packages = Tariff::query()
            ->where('is_active', true)
            ->where('category', $category)
            ->where('threshold_hours', '>', 1)
            ->orderBy('threshold_hours')
            ->get()
            ->map(function (Tariff $tariff) use ($startsAt, $hourly) {
                $hours = (int) $tariff->threshold_hours;
                $cost = (float) $tariff->price_per_package;
                $finishedAt = $startsAt?->addMinutes($hours * 60)?->toIso8601String();

                return [
                    'id' => (int) $tariff->id,
                    'title' => (string) $tariff->name,
                    'hours' => $hours,
                    'cost' => $cost,
                    'hourly_equivalent' => $hours > 0 ? round($cost / $hours, 2) : $hourly,
                    'finished_at' => $finishedAt,
                ];
            })
            ->values()
            ->all();

        return [
            'category' => $category,
            'hourly_rate' => round($hourly, 2),
            'packages' => $packages,
        ];
    }

    /**
     * Цена одного места в ₽ (не minor).
     *
     * @param  'hourly'|'packages'  $mode
     */
    public function priceForSeatRub(
        string $category,
        float $hours,
        string $mode = 'hourly',
        ?int $tariffId = null
    ): array {
        $hours = max(0.0, $hours);
        $category = strtolower(trim($category)) ?: MapZoneResolver::FALLBACK_CATEGORY;
        $hourly = $this->hourlyRateRub($category);

        if ($mode === 'packages') {
            return $this->packagePriceRub($category, $hours, $hourly, $tariffId);
        }

        $total = round($hourly * $hours, 2);

        return [
            'mode' => 'hourly',
            'category' => $category,
            'hours' => $hours,
            'hourly_rate' => $hourly,
            'tariff_id' => null,
            'package_hours' => null,
            'package_price' => null,
            'extra_hours' => 0.0,
            'total_rub' => $total,
        ];
    }

    /**
     * Сумма по местам с разными зонами.
     *
     * @param  array<int, string>  $computerZones  computer_id => category
     * @param  'hourly'|'packages'  $mode
     * @return array{total_rub: float, per_seat: list<array<string, mixed>>, category: string|null}
     */
    public function quoteSeats(
        array $computerZones,
        float $hours,
        string $mode = 'hourly',
        ?int $tariffId = null
    ): array {
        if ($computerZones === []) {
            throw ValidationException::withMessages([
                'pc_ids' => 'Выберите хотя бы одно место.',
            ]);
        }

        $categories = array_values(array_unique(array_map('strval', $computerZones)));
        $primaryCategory = $categories[0] ?? MapZoneResolver::FALLBACK_CATEGORY;

        if ($mode === 'packages') {
            if (count($categories) > 1) {
                throw ValidationException::withMessages([
                    'pc_ids' => 'Для пакетного тарифа все места должны быть из одной зоны.',
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

        foreach ($computerZones as $computerId => $category) {
            $line = $this->priceForSeatRub((string) $category, $hours, $mode, $tariffId);
            $line['computer_id'] = (int) $computerId;
            $perSeat[] = $line;
            $total += (float) $line['total_rub'];
        }

        return [
            'mode' => $mode,
            'category' => $primaryCategory,
            'hours' => $hours,
            'tariff_id' => $tariffId,
            'total_rub' => round($total, 2),
            'total_minor' => (int) round($total * 100),
            'per_seat' => $perSeat,
        ];
    }

    /**
     * Витрина: почасовые ставки по зонам + пакеты (для TariffsModal).
     *
     * @return array{rates: list<array<string, mixed>>, packages: list<array<string, mixed>>}
     */
    public function showcase(): array
    {
        $zones = Zone::query()->orderBy('name')->get();
        $rates = [];

        foreach ($zones as $zone) {
            $slug = strtolower((string) $zone->slug);
            if (in_array($slug, ['wc', 'admin'], true)) {
                continue;
            }

            $hasTariff = Tariff::query()
                ->where('is_active', true)
                ->where('category', $slug)
                ->exists();

            if (! $hasTariff && $slug !== MapZoneResolver::FALLBACK_CATEGORY) {
                continue;
            }

            $rates[] = [
                'zone' => (string) $zone->name,
                'slug' => $slug,
                'price' => (string) (int) round($this->hourlyRateRub($slug)),
                'color' => (string) ($zone->color ?: '#22c55e'),
            ];
        }

        if ($rates === []) {
            $rates[] = [
                'zone' => 'Standard',
                'slug' => MapZoneResolver::FALLBACK_CATEGORY,
                'price' => (string) (int) round($this->hourlyRateRub(MapZoneResolver::FALLBACK_CATEGORY)),
                'color' => '#22c55e',
            ];
        }

        $packages = Tariff::query()
            ->where('is_active', true)
            ->where('threshold_hours', '>', 1)
            ->orderBy('threshold_hours')
            ->orderBy('category')
            ->get()
            ->map(function (Tariff $tariff) {
                $hours = (int) $tariff->threshold_hours;
                $cost = (float) $tariff->price_per_package;
                $hourly = $this->hourlyRateRub((string) $tariff->category);
                $full = $hourly * $hours;
                $discountPct = $full > 0 ? (int) round(max(0, (1 - ($cost / $full)) * 100)) : 0;

                return [
                    'id' => (int) $tariff->id,
                    'name' => (string) $tariff->name,
                    'category' => (string) $tariff->category,
                    'hours' => $hours,
                    'cost' => $cost,
                    'discount' => $discountPct > 0 ? "Скидка {$discountPct}%" : 'Пакет',
                ];
            })
            ->values()
            ->all();

        return [
            'rates' => $rates,
            'packages' => $packages,
        ];
    }

    /**
     * @deprecated Используйте quoteSeats / priceForSeatRub
     */
    public function calculateBestPrice(float $hours)
    {
        $result = $this->priceForSeatRub(MapZoneResolver::FALLBACK_CATEGORY, $hours, 'hourly');

        return $result['total_rub'];
    }

    private function packagePriceRub(
        string $category,
        float $hours,
        float $hourly,
        ?int $tariffId
    ): array {
        $tariff = Tariff::query()
            ->whereKey($tariffId)
            ->where('is_active', true)
            ->first();

        if (! $tariff) {
            throw ValidationException::withMessages([
                'tariff_id' => 'Пакет не найден или отключён.',
            ]);
        }

        if (strtolower((string) $tariff->category) !== $category) {
            throw ValidationException::withMessages([
                'tariff_id' => 'Пакет не относится к зоне выбранных мест.',
            ]);
        }

        $packageHours = (float) $tariff->threshold_hours;
        $packagePrice = (float) $tariff->price_per_package;

        if ($hours + 0.0001 < $packageHours) {
            throw ValidationException::withMessages([
                'duration' => "Для пакета «{$tariff->name}» нужно минимум {$packageHours} ч.",
            ]);
        }

        $extraHours = max(0.0, $hours - $packageHours);
        $total = round($packagePrice + ($extraHours * $hourly), 2);

        return [
            'mode' => 'packages',
            'category' => $category,
            'hours' => $hours,
            'hourly_rate' => $hourly,
            'tariff_id' => (int) $tariff->id,
            'package_hours' => $packageHours,
            'package_price' => $packagePrice,
            'extra_hours' => $extraHours,
            'total_rub' => $total,
        ];
    }
}
