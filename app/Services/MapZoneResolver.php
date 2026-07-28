<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Computer;
use Illuminate\Support\Collection;

class MapZoneResolver
{
    public const FALLBACK_CATEGORY = 'standard';

    /**
     * @return array<int, string> computer_id => zone slug
     */
    public function resolveForComputers(Club $club, Collection $computers): array
    {
        $rects = $this->zoneRects($club);
        $map = [];

        foreach ($computers as $computer) {
            $map[(int) $computer->id] = $this->resolvePoint(
                (float) $computer->x,
                (float) $computer->y,
                $rects
            );
        }

        return $map;
    }

    public function resolveComputer(Club $club, Computer $computer): string
    {
        return $this->resolvePoint(
            (float) $computer->x,
            (float) $computer->y,
            $this->zoneRects($club)
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rects
     */
    public function resolvePoint(float $x, float $y, array $rects): string
    {
        $best = null;
        $bestArea = null;

        foreach ($rects as $rect) {
            $rx = (float) ($rect['x'] ?? 0);
            $ry = (float) ($rect['y'] ?? 0);
            $rw = (float) ($rect['w'] ?? 0);
            $rh = (float) ($rect['h'] ?? 0);
            if ($rw <= 0 || $rh <= 0) {
                continue;
            }
            if ($x < $rx || $x > $rx + $rw || $y < $ry || $y > $ry + $rh) {
                continue;
            }

            $slug = strtolower(trim((string) ($rect['type'] ?? '')));
            if ($slug === '' || in_array($slug, ['wc', 'admin'], true)) {
                continue;
            }

            $area = $rw * $rh;
            // Prefer the smallest containing zone (more specific booth).
            if ($best === null || $area < $bestArea) {
                $best = $slug;
                $bestArea = $area;
            }
        }

        return $best ?: self::FALLBACK_CATEGORY;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function zoneRects(Club $club): array
    {
        $config = $club->map_config;
        if (is_string($config)) {
            $config = json_decode($config, true);
        }
        if (! is_array($config)) {
            return [];
        }

        $rects = $config['zoneRects'] ?? [];
        if (! is_array($rects)) {
            return [];
        }

        return array_values(array_filter($rects, fn ($r) => is_array($r)));
    }
}
