<?php

namespace App\Services;

use App\Models\Space;
use App\Models\Zone;
use App\Support\RoomInfoEdge;
use App\Support\ZoneSlug;
use Illuminate\Support\Collection;

/**
 * Готовит map_config для гостевых экранов: автоподпись зоны, допы, info комнаты.
 */
class MapPresentationService
{
    /**
     * Устаревшие «заголовки секции» и дубли типов — больше не нужны:
     * тип зоны рисуется автоподписью на прямоугольнике.
     */
    private const HIDDEN_MANUAL_LABELS = [
        'STANDART',
        'STANDARD',
        'СТАНДАРТ',
        'VIP',
        'SOLO',
        'SINGL',
        'DUO',
        'TRIO',
        'KVATRO',
        'BOOTCAMP',
        'BOOTCAMP PRO',
        'BOOTCAMP-PRO',
        'BOOTKAMP',
        'BOTKAMP',
        'BOTKAMP-PROFI',
        'BOOTKAMP-PROFI',
        'BOOTCAMP-PROFI',
        'TV',
        'PS5',
        'PS',
        'WC',
    ];

    /**
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>
     */
    public function decorate(?array $config, int $clubId): array
    {
        $config = $config ?? [];
        $config = ZoneSlug::normalizeMapConfig($config);
        $rects = $config['zoneRects'] ?? [];
        if (! is_array($rects) || $rects === []) {
            return $config;
        }

        $zonesBySlug = Zone::query()
            ->get(['id', 'slug', 'name', 'color'])
            ->keyBy(fn (Zone $z) => strtolower((string) $z->slug));

        $spaces = Space::query()
            ->with(['addons' => fn ($q) => $q->where('is_active', true)->orderBy('sort')])
            ->where('club_id', $clubId)
            ->get();

        $drawable = [];
        foreach ($rects as $rect) {
            if (! is_array($rect)) {
                continue;
            }
            if ((float) ($rect['w'] ?? 0) < 0.5 || (float) ($rect['h'] ?? 0) < 0.5) {
                continue;
            }
            $drawable[] = $rect;
        }

        $decorated = [];
        foreach ($drawable as $index => $rect) {
            $slug = ZoneSlug::normalize($rect['type'] ?? '');
            $zone = $slug !== '' ? $zonesBySlug->get($slug) : null;
            $space = $this->matchSpace($spaces, $rect);

            $addons = [];
            if ($space) {
                foreach ($space->addons as $addon) {
                    $addons[] = [
                        'id' => (int) $addon->id,
                        'name' => (string) $addon->name,
                        'color' => (string) ($addon->color ?: '#22c55e'),
                        'billing_mode' => (string) $addon->billing_mode,
                    ];
                }
            } elseif (! empty($rect['addon_ids']) && is_array($rect['addon_ids'])) {
                $addons = array_map(
                    fn ($id) => ['id' => (int) $id, 'name' => '+', 'color' => '#22c55e', 'billing_mode' => 'always'],
                    $rect['addon_ids']
                );
            }

            $label = $slug !== ''
                ? strtoupper(str_replace(['_', '-'], ' ', $slug))
                : ($zone?->name ? mb_strtoupper(trim((string) $zone->name)) : null);

            $infoFromSpace = $space?->roomInfo();
            $infoFromRect = is_array($rect['info'] ?? null) ? $rect['info'] : [];
            $info = RoomInfoEdge::normalizeInfo(array_merge(
                $infoFromRect,
                array_filter($infoFromSpace ?? [], fn ($v) => $v !== null && $v !== '')
            ));

            $others = array_values(array_filter(
                $drawable,
                fn ($other, $i) => $i !== $index,
                ARRAY_FILTER_USE_BOTH
            ));
            $infoEdge = RoomInfoEdge::resolve($rect, $others, $info['info_edge'] ?? null);

            $kind = $slug === 'tv' ? 'tv' : 'pc';

            $decorated[] = array_merge($rect, [
                'label' => $label,
                'addons' => $addons,
                'info' => $info,
                'info_edge' => $infoEdge,
                'info_kind' => $kind,
                'space_id' => $space?->id,
            ]);
        }

        $config['zoneRects'] = $decorated;

        $hidden = collect($decorated)
            ->pluck('label')
            ->filter()
            ->map(fn ($n) => mb_strtoupper(trim((string) $n)))
            ->merge(
                $zonesBySlug->map(fn (Zone $z) => mb_strtoupper(trim((string) $z->name)))->values()
            )
            ->merge(self::HIDDEN_MANUAL_LABELS)
            ->unique()
            ->values()
            ->all();

        if (! empty($config['labels']) && is_array($config['labels'])) {
            $config['labels'] = array_values(array_filter(
                $config['labels'],
                function ($label) use ($hidden) {
                    if (! is_array($label)) {
                        return false;
                    }
                    $text = mb_strtoupper(trim((string) ($label['content'] ?? '')));
                    if ($text === '' || $text === 'ТЕКСТ' || $text === 'TEXT') {
                        return false;
                    }

                    return ! in_array($text, $hidden, true);
                }
            ));
        }

        return $config;
    }

    /**
     * @param  Collection<int, Space>  $spaces
     */
    private function matchSpace(Collection $spaces, array $rect): ?Space
    {
        $x = round((float) ($rect['x'] ?? 0), 2);
        $y = round((float) ($rect['y'] ?? 0), 2);
        $w = round((float) ($rect['w'] ?? 0), 2);
        $h = round((float) ($rect['h'] ?? 0), 2);

        return $spaces->first(function (Space $space) use ($x, $y, $w, $h) {
            return abs(round($space->x, 2) - $x) < 0.05
                && abs(round($space->y, 2) - $y) < 0.05
                && abs(round($space->w, 2) - $w) < 0.05
                && abs(round($space->h, 2) - $h) < 0.05;
        });
    }
}
