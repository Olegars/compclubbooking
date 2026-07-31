<?php

namespace App\Services;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Единая точка пересчёта computers.status.
 *
 * Раньше статус писал только планировщик раз в минуту, поэтому после закрытия
 * сессии ПК ещё до минуты висел занятым. Теперь те же правила вызываются
 * синхронно из мест старта/закрытия брони, а команда остаётся страховкой.
 */
class ComputerStatusService
{
    /** Статусы, которые выставляет админ вручную — пересчёт их не трогает. */
    private const PROTECTED_STATUSES = ['maintenance'];

    /**
     * Пересчитать статус конкретных ПК.
     *
     * @param  iterable<int|string>|int|null  $computerIds
     */
    public function syncFor(iterable|int|null $computerIds, ?CarbonImmutable $now = null): int
    {
        $ids = $this->normalizeIds($computerIds);
        if ($ids === []) {
            return 0;
        }

        return $this->apply($ids, $now ?? CarbonImmutable::now());
    }

    /**
     * Пересчитать статус всех ПК клуба.
     */
    public function syncAll(?CarbonImmutable $now = null): int
    {
        $ids = DB::table('computers')
            ->whereNotIn('status', self::PROTECTED_STATUSES)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            return 0;
        }

        return $this->apply($ids, $now ?? CarbonImmutable::now());
    }

    /**
     * @param  list<int>  $ids
     */
    private function apply(array $ids, CarbonImmutable $now): int
    {
        $busyIds = $this->busyComputerIds($ids, $now);
        $freeIds = array_values(array_diff($ids, $busyIds));

        $changed = 0;

        if ($busyIds !== []) {
            $changed += DB::table('computers')
                ->whereIn('id', $busyIds)
                ->whereNotIn('status', self::PROTECTED_STATUSES)
                ->where('status', '!=', 'busy')
                ->update(['status' => 'busy']);
        }

        if ($freeIds !== []) {
            $changed += DB::table('computers')
                ->whereIn('id', $freeIds)
                ->whereNotIn('status', self::PROTECTED_STATUSES)
                ->where('status', '!=', 'available')
                ->update(['status' => 'available']);
        }

        return $changed;
    }

    /**
     * ПК, занятые активной бронью прямо сейчас (новая и legacy-схема).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function busyComputerIds(array $ids, CarbonImmutable $now): array
    {
        $nowIso = $now->utc()->toIso8601String();
        $local = $now->timezone(config('app.timezone'));
        $today = $local->toDateString();
        $nowH = $local->hour + ($local->minute / 60);

        // timestamptz: сравнение через ::timestamptz, иначе Eloquent даёт ложные промахи.
        return Booking::query()
            ->where('status', 'active')
            ->whereIn('computer_id', $ids)
            ->where(function ($query) use ($nowIso, $today, $nowH) {
                $query->where(function ($modern) use ($nowIso) {
                    $modern->whereNotNull('ends_at')
                        ->whereRaw('ends_at > ?::timestamptz', [$nowIso]);
                })->orWhere(function ($legacy) use ($today, $nowH) {
                    $legacy->whereNull('ends_at')
                        ->where('date', $today)
                        ->where('start_time', '<=', $nowH)
                        ->whereRaw('(start_time + duration) > ?', [$nowH]);
                });
            })
            ->pluck('computer_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int|string>|int|null  $computerIds
     * @return list<int>
     */
    private function normalizeIds(iterable|int|null $computerIds): array
    {
        if ($computerIds === null) {
            return [];
        }

        $raw = is_int($computerIds) ? [$computerIds] : iterator_to_array(
            (function () use ($computerIds) {
                foreach ($computerIds as $id) {
                    yield $id;
                }
            })()
        );

        $ids = [];
        foreach ($raw as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
