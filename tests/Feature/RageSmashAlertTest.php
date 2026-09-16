<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Product;
use App\Models\VideoSurveillanceEvent;
use App\Models\VideoSurveillanceMarkerJob;
use App\Models\VideoSurveillanceSetting;
use App\Services\RageSmashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RageSmashAlertTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Computer $computer;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->club = Club::create(['name' => 'Smash Club', 'slug' => 'smash-club']);
        $this->computer = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'ПК-12',
            'status' => 'available',
            'kind' => 'pc',
            'hwid' => 'smash-hwid-0012',
        ]);
        $this->admin = Admin::query()->create([
            'name' => 'Owner',
            'email' => 'smash.owner@test.local',
            'password' => 'password',
            'role' => 'owner',
            'pay_type' => 'shift',
            'club_id' => $this->club->id,
        ]);
    }

    private function enableNvr(): void
    {
        $row = VideoSurveillanceSetting::forClub($this->club->id);
        $row->update([
            'is_enabled' => true,
            'provider' => 'hikvision',
            'api_base_url' => 'http://192.168.222.12',
            'api_login' => 'admin',
            'api_secret' => 'nvr-pass',
            'default_channel' => '1',
            'marker_duration_sec' => 30,
            'marker_pre_sec' => 5,
        ]);
    }

    public function test_imu_smash_logs_incident_and_one_second_nvr_mark(): void
    {
        $this->enableNvr();
        Product::create([
            'name' => 'Red Bull',
            'category' => 'Напитки',
            'price' => 180,
            'stock' => 4,
            'is_active' => true,
            'requires_marking' => false,
        ]);

        $this->postJson('/api/shell/incidents', [
            'terminal_id' => $this->computer->id,
            'type' => 'hardware_abuse',
            'severity' => 'high',
            'payload' => [
                'source' => 'imu',
                'g' => 3.6,
                'imu_ratio' => 4.1,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('created', true)
            ->assertJsonPath('video_marked', true)
            ->assertJsonPath('description', 'Удар по столу на ПК-12')
            ->assertJsonPath('calm_down.drinks.0.name', 'Red Bull');

        $row = DB::table('incidents')->first();
        $this->assertNotNull($row);
        $this->assertSame('hardware_abuse', $row->type);
        $this->assertSame($this->computer->id, (int) $row->computer_id);

        $job = VideoSurveillanceMarkerJob::query()->first();
        $this->assertNotNull($job);
        $this->assertSame(1, (int) $job->duration_sec);
        $this->assertSame(1, (int) $job->pre_sec);
        $this->assertStringContainsString('Rage-Smash', (string) $job->title);
        $this->assertStringContainsString('ПК-12', (string) $job->title);

        $this->assertTrue(
            VideoSurveillanceEvent::query()
                ->where('club_id', $this->club->id)
                ->where('trigger_key', RageSmashService::TRIGGER)
                ->exists()
        );

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Incidents')
                ->where('incidents.0.type', 'hardware_abuse')
                ->where('incidents.0.type_label', 'Удар по столу / Rage-Smash')
                ->where('incidents.0.pc_name', 'ПК-12')
            );
    }

    public function test_keymash_without_kd_drop_is_ignored(): void
    {
        $this->enableNvr();

        $this->postJson('/api/shell/incidents', [
            'hwid' => $this->computer->hwid,
            'type' => 'hardware_abuse',
            'payload' => [
                'source' => 'keymash',
                'keys' => 12,
                'window_ms' => 90,
                'deaths_window' => 0,
                'kills_window' => 0,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('reason', 'no_kd_drop')
            ->assertJsonPath('video_marked', false);

        $this->assertSame(0, DB::table('incidents')->count());
        $this->assertSame(0, VideoSurveillanceMarkerJob::query()->count());
    }

    public function test_keymash_with_gsi_death_streak_marks_video(): void
    {
        $this->enableNvr();
        $smash = app(RageSmashService::class);
        $smash->noteGsi($this->computer, ['event' => 'death']);
        $smash->noteGsi($this->computer, ['event' => 'death']);
        $smash->noteGsi($this->computer, ['event' => 'kill']);

        $this->postJson('/api/shell/incidents', [
            'terminal_id' => $this->computer->id,
            'type' => 'hardware_abuse',
            'payload' => [
                'source' => 'keymash',
                'keys' => 11,
                'window_ms' => 80,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('video_marked', true);

        $this->assertSame(1, DB::table('incidents')->where('type', 'hardware_abuse')->count());
        $this->assertSame(1, (int) VideoSurveillanceMarkerJob::query()->value('duration_sec'));
    }

    public function test_open_hardware_abuse_is_deduped_but_still_marks_video(): void
    {
        $this->enableNvr();

        $this->postJson('/api/shell/incidents', [
            'terminal_id' => $this->computer->id,
            'type' => 'hardware_abuse',
            'payload' => ['source' => 'imu', 'g' => 3.1],
        ])->assertOk()->assertJsonPath('created', true);

        $this->postJson('/api/shell/incidents', [
            'terminal_id' => $this->computer->id,
            'type' => 'hardware_abuse',
            'payload' => ['source' => 'imu', 'g' => 4.2],
        ])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('video_marked', true);

        $this->assertSame(1, DB::table('incidents')->count());
        $this->assertSame(2, VideoSurveillanceMarkerJob::query()->count());
    }
}
