<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\UserFeatureReport;
use App\Support\AdminLocation;
use App\Support\UserFeatureCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeatureEngagementController extends Controller
{
    public function index(Request $request, UserFeatureReport $report): Response
    {
        [$from, $to, $preset] = $this->range($request);
        $category = (string) $request->query('category', 'all');
        if (! array_key_exists($category, UserFeatureCatalog::categories())) {
            $category = 'all';
        }

        return Inertia::render('Admin/FeatureEngagement', $report->page(
            $from,
            $to,
            $preset,
            $category,
            mb_substr((string) $request->query('q', ''), 0, 80),
            AdminLocation::id(),
        ));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function range(Request $request): array
    {
        $preset = (string) $request->query('preset', '7d');
        $today = CarbonImmutable::now()->startOfDay();

        return match ($preset) {
            'today' => [$today, $today->endOfDay(), 'today'],
            'yesterday' => [$today->subDay(), $today->subDay()->endOfDay(), 'yesterday'],
            '30d' => [$today->subDays(29), $today->endOfDay(), '30d'],
            'custom' => $this->customRange($request),
            default => [$today->subDays(6), $today->endOfDay(), '7d'],
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function customRange(Request $request): array
    {
        $fromRaw = (string) $request->query('from', '');
        $toRaw = (string) $request->query('to', '');
        try {
            $from = $fromRaw !== '' ? CarbonImmutable::parse($fromRaw)->startOfDay() : CarbonImmutable::now()->subDays(6)->startOfDay();
            $to = $toRaw !== '' ? CarbonImmutable::parse($toRaw)->endOfDay() : CarbonImmutable::now()->endOfDay();
        } catch (\Throwable) {
            $from = CarbonImmutable::now()->subDays(6)->startOfDay();
            $to = CarbonImmutable::now()->endOfDay();
        }

        return [$from, $to, 'custom'];
    }
}
