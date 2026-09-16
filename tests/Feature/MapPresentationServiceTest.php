<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Computer;
use App\Models\Space;
use App\Models\Zone;
use App\Services\MapPresentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapPresentationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rooms_without_bound_pc_or_tv_are_marked_service(): void
    {
        $club = Club::create(['name' => 'Map Club', 'slug' => 'map-club']);
        $duo = Zone::create(['name' => 'Дуо', 'slug' => 'duo', 'color' => '#22c55e']);
        $tv = Zone::create(['name' => 'ТВ', 'slug' => 'tv', 'color' => '#a855f7']);

        $withPc = Space::create([
            'club_id' => $club->id,
            'zone_id' => $duo->id,
            'name' => 'Duo-1',
            'x' => 0,
            'y' => 0,
            'w' => 10,
            'h' => 10,
        ]);
        $empty = Space::create([
            'club_id' => $club->id,
            'zone_id' => $duo->id,
            'name' => 'Service',
            'x' => 20,
            'y' => 0,
            'w' => 10,
            'h' => 10,
        ]);
        $withTv = Space::create([
            'club_id' => $club->id,
            'zone_id' => $tv->id,
            'name' => 'TV-1',
            'x' => 40,
            'y' => 0,
            'w' => 10,
            'h' => 10,
        ]);

        Computer::create([
            'club_id' => $club->id,
            'space_id' => $withPc->id,
            'name' => 'PC-01',
            'status' => 'available',
            'kind' => 'pc',
            'x' => 2,
            'y' => 2,
        ]);
        Computer::create([
            'club_id' => $club->id,
            'space_id' => $withTv->id,
            'name' => 'TV-01',
            'status' => 'available',
            'kind' => 'tv',
            'x' => 42,
            'y' => 2,
        ]);

        $config = [
            'zoneRects' => [
                ['x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'type' => 'duo', 'c' => '#22c55e'],
                ['x' => 20, 'y' => 0, 'w' => 10, 'h' => 10, 'type' => 'duo', 'c' => '#22c55e'],
                ['x' => 40, 'y' => 0, 'w' => 10, 'h' => 10, 'type' => 'tv', 'c' => '#a855f7'],
            ],
        ];

        $rects = collect(app(MapPresentationService::class)->decorate($config, (int) $club->id)['zoneRects']);

        $pcRoom = $rects->firstWhere('space_id', $withPc->id);
        $serviceRoom = $rects->firstWhere('space_id', $empty->id);
        $tvRoom = $rects->firstWhere('space_id', $withTv->id);

        $this->assertNotNull($pcRoom);
        $this->assertFalse($pcRoom['service']);
        $this->assertSame('DUO', $pcRoom['label']);

        $this->assertNotNull($serviceRoom);
        $this->assertTrue($serviceRoom['service']);
        $this->assertSame('SERVICE', $serviceRoom['label']);

        $this->assertNotNull($tvRoom);
        $this->assertFalse($tvRoom['service']);
        $this->assertSame('TV', $tvRoom['label']);
    }

    public function test_tv_inside_room_without_space_id_still_counts_as_bound(): void
    {
        $club = Club::create(['name' => 'Geo Club', 'slug' => 'geo-club']);
        $tv = Zone::create(['name' => 'ТВ', 'slug' => 'tv', 'color' => '#a855f7']);
        Space::create([
            'club_id' => $club->id,
            'zone_id' => $tv->id,
            'name' => 'TV-geo',
            'x' => 0,
            'y' => 0,
            'w' => 10,
            'h' => 10,
        ]);
        Computer::create([
            'club_id' => $club->id,
            'space_id' => null,
            'name' => 'TV-09',
            'status' => 'available',
            'kind' => 'tv',
            'x' => 3,
            'y' => 3,
        ]);

        $rects = app(MapPresentationService::class)->decorate([
            'zoneRects' => [
                ['x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'type' => 'tv', 'c' => '#a855f7'],
            ],
        ], (int) $club->id)['zoneRects'];

        $this->assertFalse($rects[0]['service']);
        $this->assertSame('TV', $rects[0]['label']);
    }
}
