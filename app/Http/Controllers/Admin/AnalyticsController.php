<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request, AnalyticsService $analytics): Response
    {
        $days = (int) $request->query('days', 30);
        if (! in_array($days, [7, 30, 90], true)) {
            $days = 30;
        }

        $to = CarbonImmutable::now()->endOfDay();
        $from = CarbonImmutable::now()->subDays($days - 1)->startOfDay();

        $zoneId = $request->query('zone_id');
        $zoneId = $zoneId !== null && $zoneId !== '' ? (int) $zoneId : null;

        return Inertia::render('Admin/Analytics', [
            'days' => $days,
            'zone_id' => $zoneId,
            'utilization' => $analytics->utilizationHeatmap($from, $to, $zoneId),
            'cohorts' => $analytics->playerCohorts(6),
            'inventory' => $analytics->inventoryAbcXyz($from, $to),
        ]);
    }
}
