<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Computer;
use App\Models\SeatClass;
use App\Models\Space;
use App\Models\Zone;
use App\Support\RoomInfoEdge;
use App\Support\ZoneSlug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MapController extends Controller
{
    public function save(Request $request)
    {
        $clubId = (int) $request->input('club_id');
        $config = $request->input('config');
        $pcs = $request->input('pcs', []);

        if (! $clubId) {
            return response()->json(['status' => 'error', 'message' => 'club_id required'], 422);
        }

        try {
            DB::transaction(function () use ($clubId, $config, $pcs) {
                if (is_array($config)) {
                    $config = ZoneSlug::normalizeMapConfig($config);
                }
                $viewbox = is_array($config) ? ($config['viewbox'] ?? null) : null;
                DB::table('clubs')->where('id', $clubId)->update(array_filter([
                    'map_config' => json_encode($config),
                    'viewbox' => is_string($viewbox) && $viewbox !== '' ? $viewbox : null,
                    'updated_at' => now(),
                ], fn ($v) => $v !== null));

                $spaceIds = $this->syncSpaces($clubId, is_array($config) ? ($config['zoneRects'] ?? []) : []);

                $pcClassId = SeatClass::query()->where('slug', 'pc')->value('id');
                $tvClassId = SeatClass::query()->where('slug', 'tv')->value('id');
                $spaces = Space::query()->where('club_id', $clubId)->get();

                $existing = Computer::query()
                    ->where('club_id', $clubId)
                    ->get()
                    ->keyBy('id');

                $keepIds = [];
                $pcs = is_array($pcs) ? $pcs : [];

                foreach ($pcs as $pc) {
                    if (! is_array($pc) || empty($pc['name'])) {
                        continue;
                    }

                    $kind = in_array($pc['kind'] ?? 'pc', ['pc', 'tv', 'ps5'], true)
                        ? $pc['kind']
                        : 'pc';
                    $x = (float) ($pc['x'] ?? 0);
                    $y = (float) ($pc['y'] ?? 0);
                    $spaceId = $this->spaceIdForPoint($spaces, $x, $y);

                    $attrs = [
                        'club_id' => $clubId,
                        'name' => (string) $pc['name'],
                        'x' => $x,
                        'y' => $y,
                        'kind' => $kind,
                        'booth_id' => ! empty($pc['booth_id']) ? (string) $pc['booth_id'] : null,
                        'seat_class_id' => in_array($kind, ['tv', 'ps5'], true) ? $tvClassId : $pcClassId,
                        'space_id' => $spaceId,
                    ];

                    $id = isset($pc['id']) ? (int) $pc['id'] : 0;
                    /** @var Computer|null $computer */
                    $computer = ($id > 0 && $existing->has($id)) ? $existing->get($id) : null;

                    if ($computer) {
                        // hwid / status / type не трогаем с карты — иначе слетает привязка шелла
                        $computer->update($attrs);
                        $keepIds[] = (int) $computer->id;
                        continue;
                    }

                    $computer = Computer::query()->create($attrs + [
                        'status' => 'available',
                        'type' => 'standard',
                        'hwid' => null,
                    ]);
                    $keepIds[] = (int) $computer->id;

                    $now = now();
                    $installations = [];
                    foreach (DB::table('games')->pluck('id') as $gameId) {
                        $installations[] = [
                            'computer_id' => $computer->id,
                            'game_id' => $gameId,
                            'is_installed' => true,
                            'verified_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($installations !== []) {
                        DB::table('computer_games')->insert($installations);
                    }
                }

                $toDeleteQuery = Computer::query()->where('club_id', $clubId);
                if ($keepIds !== []) {
                    $toDeleteQuery->whereNotIn('id', $keepIds);
                }
                foreach ($toDeleteQuery->get() as $dead) {
                    $dead->delete();
                }

                unset($spaceIds);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Карта сохранена, комнаты и допы синхронизированы.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getMap(Request $request)
    {
        $clubId = (int) $request->query('club_id');
        $club = DB::table('clubs')->where('id', $clubId)->first();

        if (! $club) {
            return response()->json(['error' => 'Club not found'], 404);
        }

        $config = json_decode($club->map_config, true) ?: [];
        $config = ZoneSlug::normalizeMapConfig($config);
        $rects = $config['zoneRects'] ?? [];

        $spaces = Space::query()
            ->with('addons:id')
            ->where('club_id', $clubId)
            ->get();

        // Подмешиваем addon_ids и info комнаты из БД в прямоугольники карты.
        if (is_array($rects)) {
            foreach ($rects as &$rect) {
                $space = $this->matchSpace($spaces, $rect);
                $rect['addon_ids'] = $space
                    ? $space->addons->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                    : array_values(array_map('intval', $rect['addon_ids'] ?? []));
                $rect['space_id'] = $space?->id;
                if ($space) {
                    $rect['info'] = RoomInfoEdge::normalizeInfo($space->roomInfo());
                } else {
                    $rect['info'] = RoomInfoEdge::normalizeInfo(
                        is_array($rect['info'] ?? null) ? $rect['info'] : null
                    );
                }
            }
            unset($rect);
            $config['zoneRects'] = $rects;
        }

        $addons = Addon::query()
            ->where('is_active', true)
            ->with(['prices' => fn ($q) => $q->where('club_id', $clubId)])
            ->orderBy('sort')
            ->orderBy('name')
            ->get()
            ->filter(fn (Addon $addon) => $addon->prices->isNotEmpty())
            ->map(fn (Addon $addon) => [
                'id' => $addon->id,
                'name' => $addon->name,
                'slug' => $addon->slug,
                'color' => $addon->color,
                'billing_mode' => $addon->billing_mode,
                'price_per_hour' => (float) $addon->prices->first()->price_per_hour,
            ])
            ->values();

        return response()->json([
            'config' => $config,
            'pcs' => Computer::query()->where('club_id', $clubId)->get(),
            'addons' => $addons,
        ]);
    }

    /**
     * Пересоздаёт spaces клуба из zoneRects и вешает допы.
     *
     * @param  list<array<string, mixed>>  $rects
     * @return list<int>
     */
    private function syncSpaces(int $clubId, array $rects): array
    {
        $zoneIdBySlug = Zone::query()->pluck('id', 'slug')
            ->mapWithKeys(fn ($id, $slug) => [strtolower((string) $slug) => (int) $id]);

        Space::query()->where('club_id', $clubId)->each(function (Space $space) {
            $space->addons()->detach();
            $space->delete();
        });

        $ids = [];
        foreach (array_values($rects) as $index => $rect) {
            if (! is_array($rect)) {
                continue;
            }
            $slug = ZoneSlug::normalize($rect['type'] ?? '');
            $zoneId = $zoneIdBySlug[$slug] ?? null;
            if (! $zoneId) {
                continue;
            }

            $zone = Zone::query()->find($zoneId);
            $info = RoomInfoEdge::normalizeInfo(
                is_array($rect['info'] ?? null) ? $rect['info'] : null
            );
            $space = Space::query()->create([
                'club_id' => $clubId,
                'zone_id' => $zoneId,
                'name' => $zone?->name ?? $slug,
                'x' => (float) ($rect['x'] ?? 0),
                'y' => (float) ($rect['y'] ?? 0),
                'w' => (float) ($rect['w'] ?? 0),
                'h' => (float) ($rect['h'] ?? 0),
                'surcharge_per_hour' => 0,
                'cpu' => $info['cpu'],
                'gpu' => $info['gpu'],
                'monitor' => $info['monitor'],
                'screen_diagonal' => $info['screen_diagonal'],
                'ps_model' => $info['ps_model'],
                'info_edge' => $info['info_edge'],
                'sort' => $index,
            ]);

            $addonIds = array_values(array_unique(array_filter(array_map(
                'intval',
                $rect['addon_ids'] ?? []
            ))));
            if ($addonIds !== []) {
                $space->addons()->sync($addonIds);
            }

            $ids[] = $space->id;
        }

        return $ids;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Space>  $spaces
     */
    private function spaceIdForPoint($spaces, float $x, float $y): ?int
    {
        $best = null;
        $bestArea = null;
        foreach ($spaces as $space) {
            if (! $space->containsPoint($x, $y)) {
                continue;
            }
            $area = $space->w * $space->h;
            if ($best === null || $area < $bestArea) {
                $best = $space;
                $bestArea = $area;
            }
        }

        return $best?->id;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Space>  $spaces
     */
    private function matchSpace($spaces, array $rect): ?Space
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
