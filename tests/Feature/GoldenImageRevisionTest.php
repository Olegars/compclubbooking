<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Computer;
use App\Models\GoldenImageRevision;
use App\Services\ClubFeatureService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoldenImageRevisionTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Computer $computer;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'club.power.heartbeat_stale_seconds' => 180,
        ]);

        $this->club = Club::create(['name' => 'Marker Club', 'slug' => 'marker-club']);
        $this->computer = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-08',
            'status' => 'available',
            'kind' => 'pc',
            'hwid' => 'marker-hwid-0008',
        ]);
        $this->admin = Admin::query()->create([
            'name' => 'Supervisor',
            'email' => 'marker.supervisor@test.local',
            'password' => 'password',
            'role' => 'supervisor',
            'pay_type' => 'shift',
            'club_id' => $this->club->id,
        ]);
    }

    public function test_first_revision_is_auto_verified_and_rollback_goes_via_heartbeat(): void
    {
        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
        ])->assertOk();

        $this->postJson('/api/shell/golden-image/revision', [
            'hwid' => $this->computer->hwid,
            'disk_mode' => 'image',
            'files' => [
                [
                    'rel' => 'steamapps/appmanifest_730.acf',
                    'sha256' => str_repeat('a', 64),
                    'kind' => 'steam_manifest',
                    'body' => '"appid" "730"',
                ],
                [
                    'rel' => 'config.ini',
                    'sha256' => str_repeat('b', 64),
                    'kind' => 'shell_config',
                    'body' => "[Diskless]\nserver_ip=192.168.20.10\n",
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('created', true)
            ->assertJsonPath('revision_status', 'verified');

        $revisionId = (int) GoldenImageRevision::query()->value('id');
        $this->assertGreaterThan(0, $revisionId);

        $this->actingAs($this->admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/computers/rollback', [
                'computer_id' => $this->computer->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('revision_id', $revisionId);

        $this->computer->refresh();
        $commandId = (int) $this->computer->rollback_command_id;

        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
        ])->assertOk()
            ->assertJsonPath('power_action', 'none')
            ->assertJsonPath('rollback.action', 'rollback_revision')
            ->assertJsonPath('rollback.revision_id', $revisionId)
            ->assertJsonPath('rollback.command_id', $commandId);

        $this->getJson('/api/shell/golden-image/revisions/'.$revisionId.'?hwid='.$this->computer->hwid)
            ->assertOk()
            ->assertJsonPath('id', $revisionId)
            ->assertJsonPath('files.0.rel', 'steamapps/appmanifest_730.acf');

        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
            'rollback_ack_id' => $commandId,
            'rollback_result' => 'ok',
            'rollback_message' => 'Восстановлено 2/2',
        ])->assertOk()
            ->assertJsonPath('rollback', null);

        $this->computer->refresh();
        $this->assertNull($this->computer->rollback_command);
        $this->assertSame('ok', $this->computer->rollback_result);
        $this->assertSame($revisionId, (int) $this->computer->golden_revision_id);
    }

    public function test_second_revision_stays_pending_until_verified(): void
    {
        $this->postJson('/api/shell/power/heartbeat', ['hwid' => $this->computer->hwid])->assertOk();
        $this->seedRevision('aaaa');
        $second = $this->seedRevision('bbbb');

        $this->assertSame('pending', $second['revision_status']);

        $this->actingAs($this->admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/golden-image/verify', [
                'revision_id' => $second['revision_id'],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(
            GoldenImageRevision::STATUS_VERIFIED,
            GoldenImageRevision::query()->find($second['revision_id'])->status
        );
        $this->assertSame(
            GoldenImageRevision::STATUS_SUPERSEDED,
            GoldenImageRevision::query()->orderBy('id')->first()->status
        );
    }

    public function test_crash_heartbeat_opens_incident(): void
    {
        $this->postJson('/api/shell/power/heartbeat', [
            'hwid' => $this->computer->hwid,
            'crash_detected' => true,
            'crash_reason' => 'bsod',
            'crash_detail' => 'Bugcheck 0x116',
        ])->assertOk();

        $this->computer->refresh();
        $this->assertSame('bsod', $this->computer->last_crash_reason);
        $this->assertDatabaseHas('incidents', [
            'type' => 'golden_image_crash',
            'computer_id' => $this->computer->id,
        ]);
    }

    public function test_disabled_feature_rejects_ingest_and_rollback(): void
    {
        app(ClubFeatureService::class)->save($this->club->id, 'rollback_markers', false);
        $this->postJson('/api/shell/power/heartbeat', ['hwid' => $this->computer->hwid])->assertOk();

        $this->postJson('/api/shell/golden-image/revision', [
            'hwid' => $this->computer->hwid,
            'files' => [[
                'rel' => 'config.ini',
                'sha256' => str_repeat('c', 64),
                'kind' => 'shell_config',
                'body' => 'x',
            ]],
        ])->assertStatus(422);

        $this->actingAs($this->admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/computers/rollback', [
                'computer_id' => $this->computer->id,
            ])
            ->assertStatus(422);
    }

    public function test_features_page_lists_rollback_markers(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->get('/admin/config/features')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ClubFeatures')
                ->where('features', fn ($rows) => collect($rows)->contains(
                    fn ($row) => ($row['key'] ?? '') === 'rollback_markers'
                        && ($row['group'] ?? '') === 'stations'
                        && ($row['enabled'] ?? false) === true
                ))
            );
    }

    /**
     * @return array{revision_id: int, revision_status: string}
     */
    private function seedRevision(string $hashChar): array
    {
        $response = $this->postJson('/api/shell/golden-image/revision', [
            'hwid' => $this->computer->hwid,
            'files' => [[
                'rel' => 'steamapps/appmanifest_730.acf',
                'sha256' => str_repeat($hashChar[0], 64),
                'kind' => 'steam_manifest',
                'body' => $hashChar,
            ]],
        ])->assertOk();

        return [
            'revision_id' => (int) $response->json('revision_id'),
            'revision_status' => (string) $response->json('revision_status'),
        ];
    }
}
