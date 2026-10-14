<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Computer;
use App\Models\FireAlarm;
use App\Models\FireDetector;
use App\Models\FirePanel;
use App\Models\Space;
use App\Models\Zone;
use App\Services\Fire\FireAlarmService;
use App\Services\Fire\OrionFrame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FireAlarmTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Space $room;

    private Space $other;

    private Computer $pcA;

    private Computer $pcB;

    private Computer $pcOther;

    private FireDetector $detector;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Fire Club', 'slug' => 'fire-club']);
        $zone = Zone::create(['name' => 'Буткемп', 'slug' => 'bootcamp-fire']);
        $this->room = Space::create([
            'club_id' => $this->club->id,
            'zone_id' => $zone->id,
            'name' => 'Комната 3',
        ]);
        $this->other = Space::create([
            'club_id' => $this->club->id,
            'zone_id' => $zone->id,
            'name' => 'Комната 1',
        ]);
        $this->pcA = Computer::create([
            'club_id' => $this->club->id,
            'space_id' => $this->room->id,
            'name' => 'ПК-3',
            'status' => 'available',
            'kind' => 'pc',
        ]);
        $this->pcB = Computer::create([
            'club_id' => $this->club->id,
            'space_id' => $this->room->id,
            'name' => 'ПК-4',
            'status' => 'available',
            'kind' => 'pc',
        ]);
        $this->pcOther = Computer::create([
            'club_id' => $this->club->id,
            'space_id' => $this->other->id,
            'name' => 'ПК-1',
            'status' => 'available',
            'kind' => 'pc',
        ]);
        $this->detector = FireDetector::create([
            'club_id' => $this->club->id,
            'space_id' => $this->room->id,
            'device_address' => 2,
            'loop_address' => 5,
        ]);
        $this->admin = Admin::query()->create([
            'name' => 'Floor',
            'email' => 'fire.admin@test.local',
            'password' => 'password',
            'role' => 'owner',
            'pay_type' => 'shift',
            'club_id' => $this->club->id,
        ]);
    }

    public function test_smoke_locks_every_pc_in_the_room_until_the_detector_clears(): void
    {
        $alarms = app(FireAlarmService::class);
        $alarms->note($this->club->id, 2, 5, 37);

        $this->assertTrue($alarms->screenForComputer($this->pcA)['active']);
        $this->assertTrue($alarms->screenForComputer($this->pcB)['active']);
        $this->assertFalse($alarms->screenForComputer($this->pcOther)['active']);
        $this->assertSame('ПОЖАР. Срочно покиньте комнату', $alarms->screenForComputer($this->pcA)['message']);

        $alarms->note($this->club->id, 2, 5, 110);
        $this->assertTrue($alarms->screenForComputer($this->pcA)['active']);

        $alarms->requestReset($this->club->id);
        $this->assertTrue(FirePanel::query()->where('club_id', $this->club->id)->value('reset_pending'));
        $this->assertTrue($alarms->screenForComputer($this->pcB)['active']);

        $alarms->note($this->club->id, 2, 5, 39);
        $this->assertFalse($alarms->screenForComputer($this->pcA)['active']);
        $this->assertSame(
            FireAlarm::STATUS_CLEARED,
            FireAlarm::query()->where('space_id', $this->room->id)->value('status')
        );
    }

    public function test_one_clear_detector_does_not_open_the_room_while_another_still_sees_smoke(): void
    {
        FireDetector::create([
            'club_id' => $this->club->id,
            'space_id' => $this->room->id,
            'device_address' => 2,
            'loop_address' => 6,
        ]);
        $alarms = app(FireAlarmService::class);
        $alarms->note($this->club->id, 2, 5, 44);
        $alarms->note($this->club->id, 2, 6, 37);
        $alarms->note($this->club->id, 2, 5, 39);

        $this->assertTrue($alarms->screenForComputer($this->pcA)['active']);

        $alarms->note($this->club->id, 2, 6, 35);
        $this->assertFalse($alarms->screenForComputer($this->pcA)['active']);
    }

    public function test_shell_and_admin_reset_follow_the_detector(): void
    {
        app(FireAlarmService::class)->note($this->club->id, 2, 5, 37);

        $this->getJson('/api/shell/fire?terminal_id='.$this->pcA->id)
            ->assertOk()
            ->assertJsonPath('active', true);

        $this->getJson('/api/shell/fire?terminal_id='.$this->pcOther->id)
            ->assertOk()
            ->assertJsonPath('active', false);

        $this->actingAs($this->admin, 'admin')
            ->postJson('/admin/api/fire/reset')
            ->assertOk()
            ->assertJsonPath('live.count', 1);

        $this->assertTrue((bool) $this->detector->fresh()->smoke);
        $this->assertSame(1, FireAlarm::query()->where('status', 'active')->count());
    }

    public function test_frame_roundtrip_rejects_a_broken_checksum(): void
    {
        $packet = OrionFrame::build(1, OrionFrame::CMD_READ_EVENT);
        $frame = OrionFrame::parse($packet);
        $this->assertNotNull($frame);
        $this->assertSame(OrionFrame::CMD_READ_EVENT, $frame['cmd']);
        $this->assertNull(OrionFrame::event($frame));

        $event = OrionFrame::build(1, OrionFrame::CMD_READ_EVENT, "\x25\x05\x02");
        $parsed = OrionFrame::event(OrionFrame::parse($event));
        $this->assertSame(['code' => 37, 'loop' => 5, 'device' => 2], $parsed);

        $broken = substr_replace($packet, "\x00", -1);
        $this->assertNull(OrionFrame::parse($broken));
    }
}
