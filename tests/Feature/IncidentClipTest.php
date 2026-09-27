<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Computer;
use App\Models\IncidentClipJob;
use App\Models\StoreAssemblyClipJob;
use App\Models\StoreBuiltPc;
use App\Models\VideoSurveillanceSetting;
use App\Services\Hikvision\HikvisionIsapiMarker;
use App\Services\IncidentClipService;
use App\Services\StoreAssemblyCaptureService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IncidentClipTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'video-relay-secret';

    private Club $club;

    private Computer $pc;

    protected function setUp(): void
    {
        parent::setUp();

        config(['video_surveillance.relay_token' => $this->token]);

        $this->club = Club::create([
            'name' => 'Clip Club',
            'slug' => 'clip-club',
        ]);

        $this->pc = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-08',
            'status' => 'available',
            'nvr_channel' => '8',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enableHikvision(): void
    {
        VideoSurveillanceSetting::forClub($this->club->id)->update([
            'is_enabled' => true,
            'provider' => 'hikvision',
            'api_base_url' => 'http://192.168.222.12',
            'api_login' => 'admin',
            'api_secret' => 'nvr-pass',
            'default_channel' => '1',
        ]);
    }

    public function test_playback_stamp_is_hikvision_utc(): void
    {
        $at = new \DateTimeImmutable('2026-09-23 14:00:00+03:00');

        $this->assertSame('20260923T110000Z', HikvisionIsapiMarker::playbackStamp($at));
        $this->assertSame(801, HikvisionIsapiMarker::trackId('8'));
    }

    public function test_mouse_disconnect_queues_clip_thirty_seconds_before_event(): void
    {
        $this->enableHikvision();
        $event = Carbon::parse('2026-09-23 14:00:00+03:00');
        Carbon::setTestNow($event);

        $this->postJson('/api/shell/hid/alert', [
            'computer_id' => $this->pc->id,
            'type' => 'disconnected',
        ])->assertOk();

        $job = IncidentClipJob::query()->first();
        $this->assertNotNull($job);
        $this->assertSame(IncidentClipJob::SUBJECT_HID, $job->subject_type);
        $this->assertSame(IncidentClipJob::STATUS_PENDING, $job->status);
        $this->assertSame('8', $job->channel);
        $this->assertSame(801, (int) $job->track_id);
        $this->assertTrue($job->starts_at->equalTo($event->copy()->subSeconds(30)));
        $this->assertTrue($job->ends_at->equalTo($event->copy()->addSeconds(15)));
        $this->assertMatchesRegularExpression('/^inc_\d+_pc08_hid_disconnected_\d{8}_\d{6}\.mp4$/', $job->file_name);

        $this->get('/api/video/incident-clip-targets')->assertUnauthorized();

        $this->get('/api/video/incident-clip-targets?token='.$this->token)
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('jobs.0.track_id', 801)
            ->assertJsonPath('jobs.0.nvr_channel', '8')
            ->assertJsonPath('nvr.login', 'admin')
            ->assertJsonPath('jobs.0.rtsp', 'rtsp://{login}:{password}@192.168.222.12:554/Streaming/tracks/801?starttime=20260923T105930Z&endtime=20260923T110015Z');

        $this->assertSame(IncidentClipJob::STATUS_CLAIMED, $job->fresh()->status);
    }

    public function test_missing_channel_marks_clip_failed_without_queueing_ffmpeg(): void
    {
        $this->enableHikvision();
        $this->pc->update(['nvr_channel' => null]);

        $this->postJson('/api/shell/sos', [
            'computer_id' => $this->pc->id,
            'reason' => ['code' => 'peripherals', 'label' => 'Мышь'],
        ])->assertOk();

        $job = IncidentClipJob::query()->first();
        $this->assertNotNull($job);
        $this->assertSame(IncidentClipJob::STATUS_FAILED, $job->status);
        $this->assertSame('Канал NVR для этого ПК не задан', $job->last_error);

        $this->get('/api/video/incident-clip-targets?token='.$this->token)
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    public function test_surveillance_off_does_not_queue_a_clip(): void
    {
        $this->postJson('/api/shell/hid/alert', [
            'computer_id' => $this->pc->id,
            'type' => 'disconnected',
        ])->assertOk();

        $this->assertSame(0, IncidentClipJob::query()->count());
    }

    public function test_agent_upload_and_nvr_failure_do_not_500(): void
    {
        Storage::fake('local');
        $this->enableHikvision();

        $incidentId = DB::table('incidents')->insertGetId([
            'type' => 'hardware_abuse',
            'description' => 'Удар по столу на PC-08',
            'severity' => 'high',
            'computer_id' => $this->pc->id,
            'clip_status' => 'none',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = Carbon::parse('2026-09-23 19:00:00');
        $job = app(IncidentClipService::class)->enqueue(
            IncidentClipJob::SUBJECT_INCIDENT,
            $incidentId,
            $this->pc,
            $event,
            'hardware_abuse',
        );
        $this->assertNotNull($job);
        $this->assertSame('pending', DB::table('incidents')->where('id', $incidentId)->value('clip_status'));

        $this->post('/api/video/incident-clip-failed', [
            'token' => $this->token,
            'job_id' => $job->id,
            'error' => 'ffmpeg timeout',
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame(IncidentClipJob::STATUS_FAILED, $job->fresh()->status);
        $this->assertSame('failed', DB::table('incidents')->where('id', $incidentId)->value('clip_status'));

        $job->update([
            'status' => IncidentClipJob::STATUS_CLAIMED,
            'last_error' => null,
        ]);

        $file = UploadedFile::fake()->create('episode.mp4', 120, 'video/mp4');
        $this->post('/api/video/incident-clips', [
            'token' => $this->token,
            'job_id' => $job->id,
            'clip' => $file,
        ])->assertOk()->assertJsonPath('status', 'success');

        $job->refresh();
        $this->assertSame(IncidentClipJob::STATUS_SENT, $job->status);
        $this->assertTrue(str_ends_with((string) $job->file_name, '.mp4'));
        Storage::disk('local')->assertExists($job->file_path);
        $this->assertSame('ready', DB::table('incidents')->where('id', $incidentId)->value('clip_status'));
        $this->assertSame('/admin/incidents/clips/'.$job->id, DB::table('incidents')->where('id', $incidentId)->value('clip_url'));

        $this->get('/admin/incidents/clips/'.$job->id)->assertRedirect();

        $admin = Admin::create([
            'name' => 'Clip Owner',
            'email' => 'clip-owner@example.test',
            'password' => 'password',
            'role' => 'owner',
            'club_id' => $this->club->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/incidents/clips/'.$job->id)
            ->assertOk()
            ->assertHeader('content-type', 'video/mp4');
    }

    public function test_assembly_and_incident_clips_share_one_ffmpeg_slot(): void
    {
        $this->enableHikvision();

        $first = $this->pendingIncident(1, Carbon::parse('2026-09-23 12:00:00'));
        $second = $this->pendingIncident(2, Carbon::parse('2026-09-23 18:00:00'));
        $assembly = $this->pendingAssembly();

        $this->get('/api/video/incident-clip-targets?token='.$this->token.'&limit=3')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('jobs.0.id', $first->id);

        $this->assertSame(IncidentClipJob::STATUS_CLAIMED, $first->fresh()->status);
        $this->assertSame(IncidentClipJob::STATUS_PENDING, $second->fresh()->status);

        $this->get('/api/video/assembly-clip-targets?token='.$this->token.'&limit=3')
            ->assertOk()
            ->assertJsonPath('count', 0);
        $this->assertSame(StoreAssemblyClipJob::STATUS_PENDING, $assembly->fresh()->status);

        $this->get('/api/video/incident-clip-targets?token='.$this->token)
            ->assertOk()
            ->assertJsonPath('count', 0);

        app(IncidentClipService::class)->markFailed([
            ['id' => $first->id, 'error' => 'slot test'],
        ]);

        $this->get('/api/video/assembly-clip-targets?token='.$this->token)
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('jobs.0.id', $assembly->id);
        $this->assertSame(IncidentClipJob::STATUS_PENDING, $second->fresh()->status);

        $this->get('/api/video/incident-clip-targets?token='.$this->token)
            ->assertOk()
            ->assertJsonPath('count', 0);

        app(StoreAssemblyCaptureService::class)->markFailed([
            ['id' => $assembly->id, 'error' => 'slot test'],
        ]);

        $this->get('/api/video/incident-clip-targets?token='.$this->token)
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('jobs.0.id', $second->id);
    }

    public function test_stale_assembly_claim_does_not_block_an_incident_clip(): void
    {
        $this->enableHikvision();
        $incident = $this->pendingIncident(9, Carbon::parse('2026-09-23 15:00:00'));
        $assembly = $this->pendingAssembly();
        $assembly->update([
            'status' => StoreAssemblyClipJob::STATUS_CLAIMED,
            'claimed_at' => now()->subMinutes(45),
        ]);

        $this->get('/api/video/incident-clip-targets?token='.$this->token)
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('jobs.0.id', $incident->id);

        $this->assertSame(StoreAssemblyClipJob::STATUS_PENDING, $assembly->fresh()->status);
    }

    public function test_assembly_claim_still_blocks_incident_while_the_bench_clip_encodes(): void
    {
        $this->enableHikvision();
        $incident = $this->pendingIncident(11, Carbon::parse('2026-09-23 16:00:00'));
        $assembly = $this->pendingAssembly();
        $assembly->update([
            'status' => StoreAssemblyClipJob::STATUS_CLAIMED,
            'claimed_at' => now()->subMinutes(10),
        ]);

        $this->get('/api/video/incident-clip-targets?token='.$this->token)
            ->assertOk()
            ->assertJsonPath('count', 0);

        $this->assertSame(IncidentClipJob::STATUS_PENDING, $incident->fresh()->status);
        $this->assertSame(StoreAssemblyClipJob::STATUS_CLAIMED, $assembly->fresh()->status);
    }

    private function pendingIncident(int $subjectId, Carbon $event): IncidentClipJob
    {
        return IncidentClipJob::query()->create([
            'club_id' => $this->club->id,
            'subject_type' => IncidentClipJob::SUBJECT_HID,
            'subject_id' => $subjectId,
            'computer_id' => $this->pc->id,
            'status' => IncidentClipJob::STATUS_PENDING,
            'channel' => '8',
            'track_id' => 801,
            'event_at' => $event,
            'starts_at' => $event->copy()->subSeconds(30),
            'ends_at' => $event->copy()->addSeconds(15),
            'file_name' => 'inc_'.$subjectId.'_pc08_hid.mp4',
        ]);
    }

    private function pendingAssembly(): StoreAssemblyClipJob
    {
        $pc = StoreBuiltPc::query()->create([
            'club_id' => $this->club->id,
            'title' => 'Стол',
            'status' => 'assembling',
        ]);

        return StoreAssemblyClipJob::query()->create([
            'club_id' => $this->club->id,
            'store_built_pc_id' => $pc->id,
            'status' => StoreAssemblyClipJob::STATUS_PENDING,
            'channel' => '4',
            'track_id' => 401,
            'starts_at' => now()->subHour(),
            'ends_at' => now(),
        ]);
    }
}
