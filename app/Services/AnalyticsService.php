<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Computer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

class AnalyticsService
{
    /**
     * @return array{
     *   from:string,to:string,zones:list<array>,heatmap:list<array>,weekday_labels:list<string>
     * }
     */
    public function utilizationHeatmap(CarbonImmutable $from, CarbonImmutable $to, ?int $zoneId = null): array
    {
        $computers = Computer::query()
            ->with(['space.zone:id,name,color'])
            ->whereNotNull('space_id')
            ->get();

        $pcsByZone = [];
        $zoneMeta = [];
        foreach ($computers as $pc) {
            $zId = (int) ($pc->space?->zone_id ?? 0);
            if ($zId < 1) {
                continue;
            }
            if ($zoneId && $zId !== $zoneId) {
                continue;
            }
            $pcsByZone[$zId] = ($pcsByZone[$zId] ?? 0) + 1;
            if (! isset($zoneMeta[$zId])) {
                $zoneMeta[$zId] = [
                    'id' => $zId,
                    'name' => $pc->space?->zone?->name ?? ('Зона #'.$zId),
                    'color' => $pc->space?->zone?->color,
                    'pc_count' => 0,
                ];
            }
        }
        foreach ($pcsByZone as $zId => $count) {
            $zoneMeta[$zId]['pc_count'] = $count;
        }

        // occupied[zone][weekday][hour] = hours
        $occupied = [];
        // available slots: how many times each weekday-hour appears in range
        $slotCounts = array_fill(0, 7, array_fill(0, 24, 0));
        for ($d = $from->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            $wd = (int) $d->dayOfWeekIso - 1; // 0=Mon .. 6=Sun
            for ($h = 0; $h < 24; $h++) {
                $slotCounts[$wd][$h]++;
            }
        }

        $bookings = Booking::query()
            ->with(['computer.space:id,zone_id'])
            ->whereIn('status', ['completed', 'active'])
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($modern) use ($from, $to) {
                    $modern->whereNotNull('actual_started_at')
                        ->where('actual_started_at', '<', $to)
                        ->where(function ($e) use ($from) {
                            $e->whereNull('actual_ended_at')
                                ->orWhere('actual_ended_at', '>', $from);
                        });
                })->orWhere(function ($planned) use ($from, $to) {
                    $planned->whereNull('actual_started_at')
                        ->whereNotNull('starts_at')
                        ->where('starts_at', '<', $to)
                        ->where('ends_at', '>', $from);
                });
            })
            ->get();

        foreach ($bookings as $booking) {
            $zId = (int) ($booking->computer?->space?->zone_id ?? 0);
            if ($zId < 1 || ! isset($pcsByZone[$zId])) {
                continue;
            }
            if ($zoneId && $zId !== $zoneId) {
                continue;
            }

            [$start, $end] = $this->bookingWindow($booking, $from, $to);
            if (! $start || ! $end || $end->lte($start)) {
                continue;
            }

            $cursor = $start->startOfHour();
            while ($cursor->lt($end)) {
                $hourEnd = $cursor->addHour();
                $overlapStart = $start->greaterThan($cursor) ? $start : $cursor;
                $overlapEnd = $end->lessThan($hourEnd) ? $end : $hourEnd;
                $hours = max(0, $overlapStart->diffInSeconds($overlapEnd) / 3600);
                if ($hours > 0) {
                    $wd = (int) $cursor->dayOfWeekIso - 1;
                    $h = (int) $cursor->hour;
                    $occupied[$zId][$wd][$h] = ($occupied[$zId][$wd][$h] ?? 0) + $hours;
                }
                $cursor = $hourEnd;
            }
        }

        $weekdayLabels = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        $heatmap = [];
        $zonesOut = [];

        foreach ($zoneMeta as $zId => $meta) {
            $pcCount = max(1, (int) $meta['pc_count']);
            $cells = [];
            $occTotal = 0.0;
            $availTotal = 0.0;

            for ($wd = 0; $wd < 7; $wd++) {
                for ($h = 0; $h < 24; $h++) {
                    $occ = (float) ($occupied[$zId][$wd][$h] ?? 0);
                    $slots = (int) ($slotCounts[$wd][$h] ?? 0);
                    $avail = $pcCount * $slots;
                    $rate = $avail > 0 ? min(1, $occ / $avail) : 0;
                    $cells[] = [
                        'weekday' => $wd,
                        'hour' => $h,
                        'rate' => round($rate, 3),
                        'occupied_hours' => round($occ, 2),
                    ];
                    $occTotal += $occ;
                    $availTotal += $avail;
                }
            }

            $util = $availTotal > 0 ? round(($occTotal / $availTotal) * 100, 1) : 0.0;
            $zonesOut[] = array_merge($meta, [
                'utilization_percent' => $util,
                'occupied_hours' => round($occTotal, 1),
                'available_hours' => round($availTotal, 1),
            ]);
            $heatmap[] = [
                'zone_id' => $zId,
                'zone_name' => $meta['name'],
                'cells' => $cells,
            ];
        }

        usort($zonesOut, fn ($a, $b) => $b['utilization_percent'] <=> $a['utilization_percent']);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'zones' => $zonesOut,
            'heatmap' => $heatmap,
            'weekday_labels' => $weekdayLabels,
        ];
    }

    /**
     * @return array{cohorts:list<array>,vip:list<array>,summary:array}
     */
    public function playerCohorts(int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);

        $users = User::query()
            ->where('created_at', '>=', $start)
            ->get(['id', 'name', 'phone', 'created_at']);

        $userIds = $users->pluck('id')->all();

        $spendByUser = Transaction::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('type', ['booking', 'booking_upgrade', 'purchase'])
            ->selectRaw('user_id, SUM(ABS(amount)) as spend')
            ->groupBy('user_id')
            ->pluck('spend', 'user_id');

        $completedBookings = Booking::query()
            ->whereIn('user_id', $userIds)
            ->where('status', 'completed')
            ->get(['user_id', 'actual_started_at', 'starts_at', 'created_at']);

        $bookingDatesByUser = [];
        foreach ($completedBookings as $b) {
            $at = $b->actual_started_at ?? $b->starts_at ?? $b->created_at;
            if (! $at) {
                continue;
            }
            $bookingDatesByUser[$b->user_id][] = CarbonImmutable::parse($at);
        }

        $cohortBuckets = [];
        foreach ($users as $user) {
            $key = CarbonImmutable::parse($user->created_at)->format('Y-m');
            $cohortBuckets[$key][] = $user;
        }

        ksort($cohortBuckets);
        $cohorts = [];
        foreach ($cohortBuckets as $key => $cohortUsers) {
            $regMonth = CarbonImmutable::createFromFormat('Y-m', $key)->startOfMonth();
            $m1Start = $regMonth->addMonth();
            $m1End = $m1Start->endOfMonth();
            $m2Start = $regMonth->addMonths(2);
            $m2End = $m2Start->endOfMonth();

            $newCount = count($cohortUsers);
            $returned = 0;
            $m1 = 0;
            $m2 = 0;
            $spendSum = 0.0;

            foreach ($cohortUsers as $user) {
                $dates = $bookingDatesByUser[$user->id] ?? [];
                $spend = (float) ($spendByUser[$user->id] ?? 0);
                $spendSum += $spend;
                if (count($dates) >= 2) {
                    $returned++;
                }
                foreach ($dates as $d) {
                    if ($d->between($m1Start, $m1End)) {
                        $m1++;
                        break;
                    }
                }
                foreach ($dates as $d) {
                    if ($d->between($m2Start, $m2End)) {
                        $m2++;
                        break;
                    }
                }
            }

            $cohorts[] = [
                'month' => $key,
                'label' => $regMonth->locale('ru')->isoFormat('MMM YYYY'),
                'new_users' => $newCount,
                'returned' => $returned,
                'return_rate' => $newCount > 0 ? round(($returned / $newCount) * 100, 1) : 0,
                'retention_m1' => $newCount > 0 ? round(($m1 / $newCount) * 100, 1) : 0,
                'retention_m2' => $newCount > 0 ? round(($m2 / $newCount) * 100, 1) : 0,
                'spend_total' => round($spendSum, 2),
                'avg_ltv' => $newCount > 0 ? round($spendSum / $newCount, 2) : 0,
            ];
        }

        // VIP: all-time spend Pareto among all users with spend
        $allSpend = Transaction::query()
            ->whereIn('type', ['booking', 'booking_upgrade', 'purchase'])
            ->selectRaw('user_id, SUM(ABS(amount)) as spend')
            ->groupBy('user_id')
            ->orderByDesc('spend')
            ->get();

        $totalSpend = (float) $allSpend->sum('spend');
        $vipCutoff = (int) max(1, ceil($allSpend->count() * 0.2));
        $vipIds = $allSpend->take($vipCutoff)->pluck('user_id');
        $vipUsers = User::query()->whereIn('id', $vipIds)->get(['id', 'name', 'phone', 'created_at'])->keyBy('id');
        $vipDeposits = Transaction::query()
            ->whereIn('user_id', $vipIds)
            ->where('type', 'deposit')
            ->selectRaw('user_id, SUM(amount) as cash_in')
            ->groupBy('user_id')
            ->pluck('cash_in', 'user_id');

        $vip = [];
        $vipSpend = 0.0;
        foreach ($allSpend->take($vipCutoff) as $row) {
            $u = $vipUsers->get($row->user_id);
            $spend = round((float) $row->spend, 2);
            $vipSpend += $spend;
            $vip[] = [
                'user_id' => $row->user_id,
                'name' => $u?->name ?? '—',
                'phone' => $u?->phone,
                'ltv' => $spend,
                'cash_in' => round((float) ($vipDeposits[$row->user_id] ?? 0), 2),
                'share_percent' => $totalSpend > 0 ? round(($spend / $totalSpend) * 100, 2) : 0,
            ];
        }

        return [
            'cohorts' => $cohorts,
            'vip' => $vip,
            'summary' => [
                'total_spend' => round($totalSpend, 2),
                'vip_count' => count($vip),
                'vip_spend' => round($vipSpend, 2),
                'vip_share_percent' => $totalSpend > 0 ? round(($vipSpend / $totalSpend) * 100, 1) : 0,
                'users_in_window' => $users->count(),
            ],
        ];
    }

    /**
     * @return array{from:string,to:string,products:list<array>,summary:array}
     */
    public function inventoryAbcXyz(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orders = Order::query()
            ->where('status', 'delivered')
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'items', 'product_name', 'price', 'created_at']);

        $spanDays = max(1, $from->diffInDays($to) + 1);
        $third = (int) ceil($spanDays / 3);
        $t1End = $from->addDays($third - 1)->endOfDay();
        $t2End = $from->addDays(($third * 2) - 1)->endOfDay();

        /** @var array<int|string, array{product_id:?int,name:string,revenue:float,qty:int,buckets:array{0:int,1:int,2:int}}> $agg */
        $agg = [];

        foreach ($orders as $order) {
            $lines = $order->lineItems();
            $bucket = 0;
            $created = CarbonImmutable::parse($order->created_at);
            if ($created->gt($t2End)) {
                $bucket = 2;
            } elseif ($created->gt($t1End)) {
                $bucket = 1;
            }

            foreach ($lines as $line) {
                $pid = $line['product_id'] ?? null;
                $key = $pid ? 'p:'.$pid : 'n:'.mb_strtolower($line['name']);
                if (! isset($agg[$key])) {
                    $agg[$key] = [
                        'product_id' => $pid,
                        'name' => $line['name'],
                        'revenue' => 0.0,
                        'qty' => 0,
                        'buckets' => [0, 0, 0],
                    ];
                }
                $agg[$key]['revenue'] += (float) $line['line_total'];
                $agg[$key]['qty'] += (int) $line['qty'];
                $agg[$key]['buckets'][$bucket] += (int) $line['qty'];
            }
        }

        // Products with zero sales in period
        $seenIds = collect($agg)->pluck('product_id')->filter()->all();
        Product::query()
            ->where('is_active', true)
            ->when($seenIds !== [], fn ($q) => $q->whereNotIn('id', $seenIds))
            ->get(['id', 'name', 'stock'])
            ->each(function (Product $p) use (&$agg) {
                $agg['p:'.$p->id] = [
                    'product_id' => $p->id,
                    'name' => $p->name,
                    'revenue' => 0.0,
                    'qty' => 0,
                    'buckets' => [0, 0, 0],
                    'stock' => (int) $p->stock,
                ];
            });

        $rows = array_values($agg);
        usort($rows, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        $totalRevenue = array_sum(array_column($rows, 'revenue'));
        $cumulative = 0.0;
        $products = [];

        foreach ($rows as $row) {
            $before = $cumulative;
            $cumulative += $row['revenue'];
            if ($row['revenue'] <= 0 || $totalRevenue <= 0) {
                $abc = 'C';
            } elseif (($before / $totalRevenue) < 0.8) {
                $abc = 'A';
            } elseif (($before / $totalRevenue) < 0.95) {
                $abc = 'B';
            } else {
                $abc = 'C';
            }

            $b = $row['buckets'];
            $mean = (array_sum($b) / 3);
            $variance = 0.0;
            foreach ($b as $v) {
                $variance += ($v - $mean) ** 2;
            }
            $std = sqrt($variance / 3);
            $cv = $mean > 0 ? $std / $mean : 999.0;
            if ($row['qty'] <= 0) {
                $xyz = 'Z';
            } elseif ($cv < 0.5) {
                $xyz = 'X';
            } elseif ($cv < 1.0) {
                $xyz = 'Y';
            } else {
                $xyz = 'Z';
            }

            $products[] = [
                'product_id' => $row['product_id'],
                'name' => $row['name'],
                'revenue' => round($row['revenue'], 2),
                'qty' => $row['qty'],
                'stock' => $row['stock'] ?? null,
                'abc' => $abc,
                'xyz' => $xyz,
                'class' => $abc.$xyz,
                'cv' => round(min($cv, 99), 2),
                'revenue_share' => $totalRevenue > 0 ? round(($row['revenue'] / $totalRevenue) * 100, 2) : 0,
            ];
        }

        $counts = ['A' => 0, 'B' => 0, 'C' => 0, 'X' => 0, 'Y' => 0, 'Z' => 0];
        foreach ($products as $p) {
            $counts[$p['abc']]++;
            $counts[$p['xyz']]++;
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'products' => $products,
            'summary' => [
                'total_revenue' => round($totalRevenue, 2),
                'sku_count' => count($products),
                'abc' => ['A' => $counts['A'], 'B' => $counts['B'], 'C' => $counts['C']],
                'xyz' => ['X' => $counts['X'], 'Y' => $counts['Y'], 'Z' => $counts['Z']],
            ],
        ];
    }

    /**
     * @return array{0:?CarbonImmutable,1:?CarbonImmutable}
     */
    private function bookingWindow(Booking $booking, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($booking->actual_started_at) {
            $start = CarbonImmutable::parse($booking->actual_started_at);
            $end = $booking->actual_ended_at
                ? CarbonImmutable::parse($booking->actual_ended_at)
                : CarbonImmutable::now();
        } elseif ($booking->starts_at && $booking->ends_at) {
            $start = CarbonImmutable::parse($booking->starts_at);
            $end = CarbonImmutable::parse($booking->ends_at);
        } else {
            return [null, null];
        }

        if ($start->lt($from)) {
            $start = $from;
        }
        if ($end->gt($to)) {
            $end = $to;
        }

        return [$start, $end];
    }
}
