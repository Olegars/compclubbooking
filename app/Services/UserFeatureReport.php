<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Computer;
use App\Models\UserFeatureDailyStat;
use App\Models\UserFeatureEvent;
use App\Support\UserFeatureCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class UserFeatureReport
{
    public function __construct(private readonly UserFeatureTelemetry $telemetry)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function page(
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $preset,
        string $category,
        string $search,
        ?int $clubId,
    ): array {
        $from = $from->startOfDay();
        $to = $to->endOfDay();
        if ($to->lessThan($from)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }
        $span = (int) max(1, $from->startOfDay()->diffInDays($to->startOfDay()) + 1);
        if ($span > 90) {
            $from = $to->subDays(89)->startOfDay();
            $span = 90;
        }

        $prevTo = $from->subSecond();
        $prevFrom = $prevTo->subDays($span - 1)->startOfDay();
        $this->backfillDaily($prevFrom, $to);

        $keys = UserFeatureCatalog::keysForCategory($category);
        $current = $this->metrics($from, $to, $keys, $clubId);
        $previous = $this->metrics($prevFrom, $prevTo, $keys, $clubId);
        $audience = $this->audience($from, $to, $clubId);
        $retention = $this->retention($from, $to, $keys, $clubId);
        $needle = mb_strtolower(trim($search));

        $rows = [];
        foreach (UserFeatureCatalog::all() as $def) {
            if (! in_array($def['key'], $keys, true)) {
                continue;
            }
            if ($needle !== '' && ! str_contains(mb_strtolower($def['title'].' '.$def['key']), $needle)) {
                continue;
            }
            $now = $current[$def['key']] ?? $this->emptyMetric();
            $was = $previous[$def['key']] ?? $this->emptyMetric();
            $sessions = max($now['sessions'], $now['unique_users'] > 0 && $now['sessions'] === 0 ? $now['unique_users'] : 0);
            $avg = $sessions > 0 ? round($now['actions'] / $sessions, 1) : 0;
            $reach = $audience > 0 ? round($now['unique_users'] / $audience * 100, 1) : 0;
            $rows[] = [
                'key' => $def['key'],
                'title' => $def['title'],
                'icon' => $def['icon'],
                'category' => $def['category'],
                'actions' => $now['actions'],
                'unique_users' => $now['unique_users'],
                'unique_stations' => $now['unique_stations'],
                'reach_percent' => $reach,
                'avg_per_session' => $avg,
                'trend' => $this->trend($now['actions'], $was['actions']),
                'repeat_percent' => $retention[$def['key']] ?? 0,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['unique_users'] <=> $a['unique_users']
            ?: $b['actions'] <=> $a['actions']);

        $top = array_slice($rows, 0, 5);
        $topMax = max(1, ...array_map(fn (array $row) => $row['actions'], $top) ?: [1]);
        foreach ($top as &$row) {
            $row['bar_percent'] = (int) round($row['actions'] / $topMax * 100);
        }
        unset($row);

        return [
            'preset' => $preset,
            'category' => $category,
            'search' => $search,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'categories' => UserFeatureCatalog::categories(),
            'audience' => $audience,
            'rows' => $rows,
            'top' => $top,
            'retention' => $this->retentionCards($rows),
            'dead' => $this->deadZones($clubId),
        ];
    }

    private function backfillDaily(CarbonImmutable $from, CarbonImmutable $to): void
    {
        $yesterday = CarbonImmutable::now()->subDay()->startOfDay();
        $cursor = $from->startOfDay();
        $end = $to->startOfDay()->min($yesterday);
        while ($cursor->lessThanOrEqualTo($end)) {
            $exists = UserFeatureDailyStat::query()->whereDate('date', $cursor->toDateString())->exists();
            if (! $exists && UserFeatureEvent::query()->whereBetween('created_at', [$cursor->startOfDay(), $cursor->endOfDay()])->exists()) {
                $this->telemetry->aggregateDate($cursor);
            }
            $cursor = $cursor->addDay();
        }
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{actions:int, unique_users:int, unique_stations:int, sessions:int}>
     */
    private function metrics(CarbonImmutable $from, CarbonImmutable $to, array $keys, ?int $clubId): array
    {
        if ($keys === []) {
            return [];
        }
        $today = CarbonImmutable::now()->startOfDay();
        $actions = [];

        $dailyEnd = $to->startOfDay()->min($today->subDay());
        if ($from->startOfDay()->lessThanOrEqualTo($dailyEnd)) {
            $daily = UserFeatureDailyStat::query()
                ->whereBetween('date', [$from->toDateString(), $dailyEnd->toDateString()])
                ->whereIn('feature_key', $keys)
                ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
                ->selectRaw('feature_key, sum(total_actions) as actions')
                ->groupBy('feature_key')
                ->get();
            foreach ($daily as $row) {
                $actions[$row->feature_key] = (int) $row->actions;
            }
        }

        $liveFrom = $from->max($today);
        if ($liveFrom->lessThanOrEqualTo($to)) {
            $live = UserFeatureEvent::query()
                ->whereBetween('created_at', [$liveFrom, $to])
                ->whereIn('feature_key', $keys)
                ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
                ->selectRaw('feature_key, count(*) as actions')
                ->groupBy('feature_key')
                ->get();
            foreach ($live as $row) {
                $actions[$row->feature_key] = ($actions[$row->feature_key] ?? 0) + (int) $row->actions;
            }
        }

        $people = UserFeatureEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('feature_key', $keys)
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->selectRaw('feature_key, count(distinct user_id) as unique_users, count(distinct terminal_id) as unique_stations, count(distinct booking_id) as sessions')
            ->groupBy('feature_key')
            ->get();

        $out = [];
        foreach ($keys as $key) {
            $hit = $people->firstWhere('feature_key', $key);
            $out[$key] = [
                'actions' => $actions[$key] ?? 0,
                'unique_users' => (int) ($hit->unique_users ?? 0),
                'unique_stations' => (int) ($hit->unique_stations ?? 0),
                'sessions' => (int) ($hit->sessions ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, float>
     */
    private function retention(CarbonImmutable $from, CarbonImmutable $to, array $keys, ?int $clubId): array
    {
        if ($keys === []) {
            return [];
        }
        $day = $this->telemetry->dayExpression('created_at');
        $pairs = UserFeatureEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('feature_key', $keys)
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->selectRaw("feature_key, user_id, count(distinct {$day}) as days")
            ->groupBy('feature_key', 'user_id')
            ->get();

        $total = [];
        $repeat = [];
        foreach ($pairs as $pair) {
            $total[$pair->feature_key] = ($total[$pair->feature_key] ?? 0) + 1;
            if ((int) $pair->days >= 2) {
                $repeat[$pair->feature_key] = ($repeat[$pair->feature_key] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($total as $key => $count) {
            $out[$key] = $count > 0 ? round(($repeat[$key] ?? 0) / $count * 100, 1) : 0;
        }

        return $out;
    }

    private function audience(CarbonImmutable $from, CarbonImmutable $to, ?int $clubId): int
    {
        $query = Booking::query()
            ->whereNotNull('user_id')
            ->whereNotIn('status', ['cancelled', 'canceled'])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('starts_at', [$from, $to])
                    ->orWhereBetween('actual_started_at', [$from, $to]);
            });
        if ($clubId) {
            $query->whereIn('computer_id', Computer::query()->where('club_id', $clubId)->select('id'));
        }

        return (int) $query->count(DB::raw('distinct user_id'));
    }

    /**
     * @return list<array{key:string, title:string, icon:string, club_feature:string}>
     */
    private function deadZones(?int $clubId): array
    {
        $since = CarbonImmutable::now()->subDays(14)->startOfDay();
        if ($this->audience($since, CarbonImmutable::now()->endOfDay(), $clubId) < 1) {
            return [];
        }
        $used = UserFeatureEvent::query()
            ->where('created_at', '>=', $since)
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->distinct()
            ->pluck('feature_key')
            ->all();
        $features = app(ClubFeatureService::class);
        $dead = [];
        foreach (UserFeatureCatalog::all() as $def) {
            if (! $def['club_feature']) {
                continue;
            }
            if (! $features->enabled($clubId, $def['club_feature'])) {
                continue;
            }
            if (in_array($def['key'], $used, true)) {
                continue;
            }
            $dead[] = [
                'key' => $def['key'],
                'title' => $def['title'],
                'icon' => $def['icon'],
                'club_feature' => $def['club_feature'],
            ];
        }

        return $dead;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function retentionCards(array $rows): array
    {
        $watch = ['voice_ai_f1', 'lan_arena_challenge', 'lfg_party_search', 'lucky_seat_claim'];
        $cards = [];
        foreach ($rows as $row) {
            if (! in_array($row['key'], $watch, true)) {
                continue;
            }
            $cards[] = [
                'key' => $row['key'],
                'title' => $row['title'],
                'icon' => $row['icon'],
                'repeat_percent' => $row['repeat_percent'],
                'unique_users' => $row['unique_users'],
            ];
        }

        return $cards;
    }

    /**
     * @return array{direction:string, percent:float}
     */
    private function trend(int $current, int $previous): array
    {
        if ($current === $previous) {
            return ['direction' => 'flat', 'percent' => 0];
        }
        if ($previous === 0) {
            return ['direction' => 'up', 'percent' => 100];
        }
        $delta = round(($current - $previous) / $previous * 100, 1);

        return [
            'direction' => $delta > 0 ? 'up' : 'down',
            'percent' => abs($delta),
        ];
    }

    /**
     * @return array{actions:int, unique_users:int, unique_stations:int, sessions:int}
     */
    private function emptyMetric(): array
    {
        return [
            'actions' => 0,
            'unique_users' => 0,
            'unique_stations' => 0,
            'sessions' => 0,
        ];
    }
}
