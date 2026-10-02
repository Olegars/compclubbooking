<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Computer;
use App\Models\YieldRule;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Почасовая ставка остаётся в правиле тарифа. Здесь к ней применяется одно
 * подходящее правило yield: день, окно и загрузка зоны на этот час.
 */
class YieldPricingService
{
    /** @var array<int, Collection<int, YieldRule>> */
    private array $rulesCache = [];

    /** @var array<string, array{seats: int, busy: int, utilization_percent: float|null, rule: YieldRule|null}> */
    private array $inspectCache = [];

    /** @var array<string, list<int>> */
    private array $seatCache = [];

    /**
     * @return array{rate: float, list_rate: float, yield: array<string, mixed>|null}
     */
    public function apply(int $clubId, int $zoneId, CarbonImmutable $at, float $listRate): array
    {
        $list = round($listRate, 2);
        $inspect = $this->inspect($clubId, $zoneId, $at);
        $rule = $inspect['rule'];

        if (! $rule) {
            return [
                'rate' => $list,
                'list_rate' => $list,
                'yield' => null,
            ];
        }

        $rate = $this->adjust($list, $rule);

        return [
            'rate' => $rate,
            'list_rate' => $list,
            'yield' => [
                'id' => (int) $rule->id,
                'name' => (string) $rule->name,
                'kind' => (string) $rule->kind,
                'adjust_percent' => (float) $rule->adjust_percent,
                'utilization_percent' => $inspect['utilization_percent'],
                'list_rate' => $list,
                'rate' => $rate,
            ],
        ];
    }

    public function adjust(float $listRate, YieldRule $rule): float
    {
        $rate = $listRate * (1 + ((float) $rule->adjust_percent / 100));

        return round(max(0, $rate), 2);
    }

    /**
     * @return array{seats: int, busy: int, utilization_percent: float|null, rule: YieldRule|null}
     */
    public function inspect(int $clubId, int $zoneId, CarbonImmutable $at): array
    {
        $key = $clubId.'|'.$zoneId.'|'.$at->format('Y-m-d H:i');
        if (isset($this->inspectCache[$key])) {
            return $this->inspectCache[$key];
        }

        $ids = $this->seatIds($clubId, $zoneId);
        $seats = count($ids);
        if ($seats === 0) {
            return $this->inspectCache[$key] = [
                'seats' => 0,
                'busy' => 0,
                'utilization_percent' => null,
                'rule' => null,
            ];
        }

        $windowEnd = $at->addMinutes(15);
        $occupied = app(GameBookingService::class)->occupiedComputerIds($ids, $at, $windowEnd);
        $busyIds = array_values(array_intersect($occupied, $ids));

        if ($this->isLive($at)) {
            $liveIds = Computer::query()
                ->whereIn('id', $ids)
                ->where('status', '!=', 'available')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $busyIds = array_values(array_unique([...$busyIds, ...$liveIds]));
        }

        $percent = round((count($busyIds) / $seats) * 100, 1);

        return $this->inspectCache[$key] = [
            'seats' => $seats,
            'busy' => count($busyIds),
            'utilization_percent' => $percent,
            'rule' => $this->matchingRule($clubId, $zoneId, $at, $percent),
        ];
    }

    public function installPresets(int $clubId): int
    {
        $created = 0;
        foreach (YieldRule::presets() as $preset) {
            $exists = YieldRule::query()
                ->where('club_id', $clubId)
                ->where('name', $preset['name'])
                ->exists();
            if ($exists) {
                continue;
            }

            YieldRule::query()->create([
                ...$preset,
                'club_id' => $clubId,
                'zone_id' => null,
                'is_active' => true,
            ]);
            $created++;
            unset($this->rulesCache[$clubId]);
        }

        return $created;
    }

    /**
     * @return list<int>
     */
    private function seatIds(int $clubId, int $zoneId): array
    {
        $key = $clubId.'|'.$zoneId;
        if (isset($this->seatCache[$key])) {
            return $this->seatCache[$key];
        }

        $clubUsesSpaces = Computer::query()
            ->where('club_id', $clubId)
            ->whereNotNull('space_id')
            ->exists();

        if ($clubUsesSpaces) {
            return $this->seatCache[$key] = Computer::query()
                ->where('club_id', $clubId)
                ->where('status', '!=', 'maintenance')
                ->whereHas('space', function ($query) use ($clubId, $zoneId) {
                    $query->where('club_id', $clubId)->where('zone_id', $zoneId);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $zone = Zone::query()->find($zoneId);
        $club = Club::query()->find($clubId);
        if (! $zone || ! $club) {
            return $this->seatCache[$key] = [];
        }

        $computers = $club->computers()
            ->where('status', '!=', 'maintenance')
            ->get();
        $slug = strtolower((string) $zone->slug);
        $map = app(MapZoneResolver::class)->resolveForComputers($club, $computers);
        $ids = [];
        foreach ($computers as $computer) {
            if (($map[(int) $computer->id] ?? '') === $slug) {
                $ids[] = (int) $computer->id;
            }
        }

        return $this->seatCache[$key] = $ids;
    }

    private function matchingRule(int $clubId, int $zoneId, CarbonImmutable $at, float $percent): ?YieldRule
    {
        $matched = $this->rulesFor($clubId)->filter(function (YieldRule $rule) use ($zoneId, $at, $percent) {
            if (! $rule->is_active) {
                return false;
            }
            if ($rule->zone_id !== null && (int) $rule->zone_id !== $zoneId) {
                return false;
            }
            if (! $rule->covers($at)) {
                return false;
            }

            return $rule->matchesUtilization($percent);
        });

        return $matched->sort(function (YieldRule $a, YieldRule $b) {
            $specificity = ($a->zone_id ? 0 : 1) <=> ($b->zone_id ? 0 : 1);
            if ($specificity !== 0) {
                return $specificity;
            }

            $priority = (int) $b->priority <=> (int) $a->priority;
            if ($priority !== 0) {
                return $priority;
            }

            return abs((float) $b->adjust_percent) <=> abs((float) $a->adjust_percent);
        })->first();
    }

    /**
     * @return Collection<int, YieldRule>
     */
    private function rulesFor(int $clubId): Collection
    {
        if (! isset($this->rulesCache[$clubId])) {
            $this->rulesCache[$clubId] = YieldRule::query()
                ->where('club_id', $clubId)
                ->orderByDesc('priority')
                ->get();
        }

        return $this->rulesCache[$clubId];
    }

    private function isLive(CarbonImmutable $at): bool
    {
        $now = CarbonImmutable::now($at->getTimezone());

        return abs($now->getTimestamp() - $at->getTimestamp()) <= 15 * 60;
    }
}
