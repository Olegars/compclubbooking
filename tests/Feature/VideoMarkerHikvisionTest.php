<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Computer;
use App\Models\VideoSurveillanceEvent;
use App\Models\VideoSurveillanceMarkerJob;
use App\Models\VideoSurveillanceSetting;
use App\Services\Hikvision\HikvisionIsapiMarker;
use App\Services\VideoMarkerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VideoMarkerHikvisionTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'video-relay-secret';

    private Club $club;

    private Computer $pc;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'video_surveillance.relay_token' => $this->token,
        ]);

        $this->club = Club::create([
            'name' => 'Marker Club',
            'slug' => 'marker-club',
        ]);

        $this->pc = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-08',
            'status' => 'available',
        ]);
    }

    private function enableHikvision(): VideoSurveillanceSetting
    {
        $row = VideoSurveillanceSetting::forClub($this->club->id);
        $row->update([
            'is_enabled' => true,
            'provider' => 'hikvision',
            'api_base_url' => 'http://192.168.1.64',
            'api_login' => 'admin',
            'api_secret' => 'nvr-pass',
            'default_channel' => '1',
            'marker_duration_sec' => 30,
            'marker_pre_sec' => 5,
        ]);

        return $row->fresh();
    }

    public function test_track_id_from_channel_number(): void
    {
        $this->assertSame(101, HikvisionIsapiMarker::trackId('1'));
        $this->assertSame(1201, HikvisionIsapiMarker::trackId('12'));
        $this->assertSame(101, HikvisionIsapiMarker::trackId('101'));
        $this->assertSame(101, HikvisionIsapiMarker::trackId('cam-01'));
        $this->assertNull(HikvisionIsapiMarker::trackId(''));
    }

    public function test_record_tag_xml_contains_name_and_time(): void
    {
        $at = \Carbon\Carbon::parse('2026-08-14T22:00:00+03:00');
        $xml = HikvisionIsapiMarker::recordTagXml('HID · PC-08', $at);

        $this->assertStringContainsString('<name>HID · PC-08</name>', $xml);
        $this->assertStringContainsString('<time>'.$at->format('Y-m-d\TH:i:sP').'</time>', $xml);
        $this->assertSame(
            'http://192.168.1.64/ISAPI/ContentMgmt/record/tracks/101/recordTag',
            HikvisionIsapiMarker::absoluteUrl('http://192.168.1.64', HikvisionIsapiMarker::tagPath(101))
        );
    }

    public function test_hikvision_enqueues_job_instead_of_http(): void
    {
        Http::fake();
        $this->enableHikvision();

        VideoSurveillanceEvent::query()->create([
            'club_id' => $this->club->id,
            'code' => 'mouse_disconnect',
            'name' => 'Отключение мыши',
            'is_enabled' => true,
            'trigger_key' => 'hid.disconnected',
            'marker_title' => 'HID',
        ]);

        $this->postJson('/api/shell/hid/alert', [
            'computer_id' => $this->pc->id,
            'type' => 'disconnected',
        ])->assertOk()->assertJsonPath('status', 'success');

        Http::assertNothingSent();

        $job = VideoSurveillanceMarkerJob::query()->first();
        $this->assertNotNull($job);
        $this->assertSame(VideoSurveillanceMarkerJob::STATUS_PENDING, $job->status);
        $this->assertSame(101, $job->track_id);
        $this->assertStringContainsString('PC-08', $job->title);
    }

    public function test_sos_enqueues_hikvision_job(): void
    {
        $this->enableHikvision();

        VideoSurveillanceEvent::query()->create([
            'club_id' => $this->club->id,
            'code' => 'sos',
            'name' => 'SOS',
            'is_enabled' => true,
            'trigger_key' => 'sos',
        ]);

        $this->postJson('/api/shell/sos', [
            'computer_id' => $this->pc->id,
            'reason' => [
                'code' => 'peripherals',
                'label' => 'Периферия',
            ],
        ])->assertOk();

        $this->assertSame(1, VideoSurveillanceMarkerJob::query()->count());
        $this->assertStringContainsString('SOS', (string) VideoSurveillanceMarkerJob::query()->value('title'));
    }

    public function test_generic_webhook_still_posts_json(): void
    {
        Http::fake([
            'https://nvr.example/markers' => Http::response(['ok' => true], 200),
        ]);

        $row = VideoSurveillanceSetting::forClub($this->club->id);
        $row->update([
            'is_enabled' => true,
            'provider' => 'generic_webhook',
            'api_base_url' => 'https://nvr.example',
            'webhook_path' => '/markers',
            'webhook_method' => 'POST',
        ]);

        $ok = app(VideoMarkerService::class)->placeMarker([
            'title' => 'Test',
            'event' => 'admin_test',
            'channel' => 'cam-1',
        ], $this->club->id);

        $this->assertTrue($ok);
        $this->assertSame(0, VideoSurveillanceMarkerJob::query()->count());
        Http::assertSent(function ($request) {
            return $request->url() === 'https://nvr.example/markers'
                && $request->method() === 'POST'
                && $request['title'] === 'Test';
        });
    }

    public function test_relay_claim_and_applied(): void
    {
        $this->enableHikvision();
        app(VideoMarkerService::class)->placeMarker([
            'title' => 'Reactor test marker',
            'event' => 'admin_test',
            'channel' => '1',
        ], $this->club->id);

        $claim = $this->getJson('/api/video/marker-targets?token='.$this->token)
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('nvr.login', 'admin')
            ->assertJsonPath('nvr.password', 'nvr-pass')
            ->assertJsonPath('jobs.0.track_id', 101);

        $jobId = (int) $claim->json('jobs.0.id');
        $tag = collect($claim->json('jobs.0.requests'))->firstWhere('id', 'tag');
        $this->assertSame('PUT', $tag['method']);
        $this->assertStringContainsString('/ISAPI/ContentMgmt/record/tracks/101/recordTag', $tag['url']);
        $this->assertStringContainsString('<RecordTag', $tag['body']);

        $this->assertDatabaseHas('video_surveillance_marker_jobs', [
            'id' => $jobId,
            'status' => VideoSurveillanceMarkerJob::STATUS_CLAIMED,
        ]);

        $this->postJson('/api/video/marker-applied', [
            'token' => $this->token,
            'sent_ids' => [$jobId],
        ])->assertOk()->assertJsonPath('sent', 1);

        $this->assertDatabaseHas('video_surveillance_marker_jobs', [
            'id' => $jobId,
            'status' => VideoSurveillanceMarkerJob::STATUS_SENT,
        ]);
    }

    public function test_relay_rejects_bad_token(): void
    {
        $this->getJson('/api/video/marker-targets?token=wrong')
            ->assertStatus(401);
    }

    public function test_hikvision_disabled_on_relay_when_webhook_provider(): void
    {
        $row = VideoSurveillanceSetting::forClub($this->club->id);
        $row->update([
            'is_enabled' => true,
            'provider' => 'generic_webhook',
            'api_base_url' => 'https://nvr.example',
        ]);

        $this->getJson('/api/video/marker-targets?token='.$this->token)
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('count', 0);
    }
}
