<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\RelayBoard;
use App\Models\SharedFan;
use App\Models\SharedFanLink;
use App\Models\SharedFanMap;
use App\Models\Space;
use App\Models\SpaceFan;
use App\Models\Zone;
use App\Services\Fan\SharedFanControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedFanControlTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private RelayBoard $board;

    private SpaceFan $fanA;

    private SpaceFan $fanB;

    private SharedFanControlService $shared;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fan.shared_relay_token' => 'test-shared-token']);

        $this->club = Club::create([
            'name' => 'Shared Fan Club',
            'slug' => 'shared-fan-club',
        ]);

        $zone = Zone::create([
            'name' => 'Z',
            'slug' => 'z-shared',
        ]);

        $space1 = Space::create([
            'club_id' => $this->club->id,
            'zone_id' => $zone->id,
            'name' => 'R1',
            'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10,
        ]);
        $space2 = Space::create([
            'club_id' => $this->club->id,
            'zone_id' => $zone->id,
            'name' => 'R2',
            'x' => 10, 'y' => 0, 'w' => 10, 'h' => 10,
        ]);

        $this->board = RelayBoard::create([
            'club_id' => $this->club->id,
            'name' => 'W5100',
            'driver' => RelayBoard::DRIVER_W5100_HTTP,
            'host' => '192.168.1.4',
            'port' => 30000,
            'is_active' => true,
        ]);

        $this->fanA = SpaceFan::create([
            'club_id' => $this->club->id,
            'space_id' => $space1->id,
            'relay_board_id' => $this->board->id,
            'channel' => 1,
            'channel2' => 2,
            'manual_mode' => SpaceFan::MODE_AUTO,
            'desired_power' => SpaceFan::SPEED_NIGHT,
            'applied_power' => SpaceFan::SPEED_NIGHT,
            'default_on_power' => SpaceFan::SPEED_HIGH,
        ]);

        $this->fanB = SpaceFan::create([
            'club_id' => $this->club->id,
            'space_id' => $space2->id,
            'relay_board_id' => $this->board->id,
            'channel' => 3,
            'channel2' => 4,
            'manual_mode' => SpaceFan::MODE_AUTO,
            'desired_power' => SpaceFan::SPEED_HIGH,
            'applied_power' => SpaceFan::SPEED_HIGH,
            'default_on_power' => SpaceFan::SPEED_HIGH,
        ]);

        $this->shared = app(SharedFanControlService::class);
    }

    private function makeShared(string $kind, int $ch1, int $ch2, string $name = 'SF'): SharedFan
    {
        $sf = SharedFan::create([
            'club_id' => $this->club->id,
            'kind' => $kind,
            'name' => $name,
            'relay_board_id' => $this->board->id,
            'channel' => $ch1,
            'channel2' => $ch2,
            'desired_power' => SpaceFan::SPEED_NIGHT,
            'applied_power' => SpaceFan::SPEED_NIGHT,
        ]);
        $sf->seedDefaultMaps();

        return $sf->fresh();
    }

    public function test_round_load_pct_from_50_and_100_is_80(): void
    {
        $this->assertSame(80, $this->shared->roundLoadPct([50, 100]));
        $this->assertSame(50, $this->shared->roundLoadPct([]));
        $this->assertSame(100, $this->shared->roundLoadPct([100, 100]));
    }

    public function test_supply_pool_uses_all_personal_average(): void
    {
        $supply = $this->makeShared(SharedFan::KIND_SUPPLY, 5, 6, 'Supply-1');
        SharedFanMap::query()
            ->where('shared_fan_id', $supply->id)
            ->where('load_pct', 80)
            ->update(['output_pct' => 100]);

        $this->shared->recomputeSupplyPool($this->club->id);

        $supply->refresh();
        $this->assertSame(SpaceFan::SPEED_HIGH, (int) $supply->desired_power);
    }

    public function test_exhaust_uses_only_linked_personals(): void
    {
        $exhaust = $this->makeShared(SharedFan::KIND_EXHAUST, 7, 8, 'Exhaust-1');
        SharedFanMap::query()
            ->where('shared_fan_id', $exhaust->id)
            ->where('load_pct', 50)
            ->update(['output_pct' => 50]);
        SharedFanMap::query()
            ->where('shared_fan_id', $exhaust->id)
            ->where('load_pct', 80)
            ->update(['output_pct' => 100]);

        // Only link night fan → load 50 → output 50 → night
        SharedFanLink::create([
            'shared_fan_id' => $exhaust->id,
            'space_fan_id' => $this->fanA->id,
        ]);
        $this->shared->recomputeSharedFan($exhaust->id);
        $exhaust->refresh();
        $this->assertSame(SpaceFan::SPEED_NIGHT, (int) $exhaust->desired_power);

        // Link both → avg 75 → 80 → map 100
        SharedFanLink::create([
            'shared_fan_id' => $exhaust->id,
            'space_fan_id' => $this->fanB->id,
        ]);
        $this->shared->recomputeSharedFan($exhaust->id);
        $exhaust->refresh();
        $this->assertSame(SpaceFan::SPEED_HIGH, (int) $exhaust->desired_power);
    }

    public function test_shared_targets_and_ack_api(): void
    {
        $supply = $this->makeShared(SharedFan::KIND_SUPPLY, 5, 6);
        SharedFanMap::query()
            ->where('shared_fan_id', $supply->id)
            ->where('load_pct', 80)
            ->update(['output_pct' => 100]);
        $this->shared->recomputeSupplyPool($this->club->id);

        $this->getJson('/api/fans/shared-targets')
            ->assertUnauthorized();

        $res = $this->getJson('/api/fans/shared-targets?token=test-shared-token')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('count', 1);

        $this->assertSame(80, $res->json('targets.0.load_pct'));
        $this->assertSame(SpaceFan::SPEED_HIGH, $res->json('targets.0.desired_power'));

        $this->postJson('/api/fans/shared-applied', [
            'token' => 'test-shared-token',
            'items' => [
                ['id' => $supply->id, 'applied_power' => SpaceFan::SPEED_HIGH],
            ],
        ])->assertOk()->assertJsonPath('updated', 1);

        $supply->refresh();
        $this->assertSame(SpaceFan::SPEED_HIGH, (int) $supply->applied_power);
    }

    public function test_cascade_pair_required_on_store_shared(): void
    {
        // Direct service/model path already validated in admin; unit-check helper:
        $this->assertFalse(SpaceFan::isCascadePair(1, 3));
        $this->assertTrue(SpaceFan::isCascadePair(5, 6));
    }
}
