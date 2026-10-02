<?php

namespace Tests\Feature;

use App\Models\AvatarStylizeJob;
use App\Models\User;
use App\Services\AvatarStylizeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarStylizeRelayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        config(['ai_assistant.avatar.relay_token' => 'avatar-relay-test']);

        $this->user = User::create([
            'name' => 'StylizeUser',
            'phone' => '+79990001122',
            'email' => 'stylize@example.test',
            'password' => 'password',
            'avatar' => 'custom/u_old.png',
        ]);
        Storage::disk('public')->put('avatars/u_old.png', $this->tinyPngBytes());
    }

    public function test_relay_rejects_empty_or_wrong_token(): void
    {
        $this->getJson('/api/avatar/stylize-targets')->assertUnauthorized();
        $this->getJson('/api/avatar/stylize-targets?token=wrong')->assertUnauthorized();

        config(['ai_assistant.avatar.relay_token' => '']);
        $this->getJson('/api/avatar/stylize-targets?token=avatar-relay-test')->assertUnauthorized();
    }

    public function test_claim_source_and_apply_replaces_avatar(): void
    {
        $job = $this->enqueue();
        $styled = $this->tinyPngBytes();

        $claim = $this->getJson('/api/avatar/stylize-targets?token=avatar-relay-test')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('jobs.0.job_id', $job->id)
            ->assertJsonPath('jobs.0.sample_url', url('/images/avatars/avatar_1.png'))
            ->assertJsonPath('comfyui', 'http://127.0.0.1:8188');

        $this->assertStringContainsString('/api/avatar/stylize-sources/'.$job->id, (string) $claim->json('jobs.0.photo_url'));
        $this->assertStringNotContainsString('token=', (string) $claim->json('jobs.0.photo_url'));

        $source = $this->get('/api/avatar/stylize-sources/'.$job->id.'?token=avatar-relay-test')
            ->assertOk();
        $this->assertStringStartsWith("\x89PNG", $source->getContent());

        $this->post('/api/avatar/stylize-applied', [
            'token' => 'avatar-relay-test',
            'job_id' => $job->id,
            'image' => base64_encode($styled),
        ])->assertOk()->assertJsonPath('replaced', true);

        $this->user->refresh();
        $this->assertNotSame('custom/u_old.png', $this->user->avatar);
        $this->assertStringStartsWith('custom/u'.$this->user->id.'_', $this->user->avatar);
        Storage::disk('public')->assertMissing('avatars/u_old.png');
        Storage::disk('public')->assertExists('avatars/'.basename($this->user->avatar));
        $this->assertSame(AvatarStylizeJob::STATUS_APPLIED, $job->fresh()->status);
        $this->getJson('/api/avatar/stylize-sources/'.$job->id.'?token=avatar-relay-test')->assertNotFound();
    }

    public function test_timeout_keeps_local_composite(): void
    {
        $job = $this->enqueue();
        $this->getJson('/api/avatar/stylize-targets?token=avatar-relay-test')->assertOk();

        $this->post('/api/avatar/stylize-applied', [
            'token' => 'avatar-relay-test',
            'job_id' => $job->id,
            'status' => 'failed',
            'error' => 'comfy timeout',
        ])->assertOk()->assertJsonPath('replaced', false);

        $this->user->refresh();
        $this->assertSame('custom/u_old.png', $this->user->avatar);
        Storage::disk('public')->assertExists('avatars/u_old.png');
        $this->assertSame('comfy timeout', $job->fresh()->last_error);
    }

    public function test_apply_does_not_overwrite_a_newer_avatar(): void
    {
        $job = $this->enqueue();
        $this->getJson('/api/avatar/stylize-targets?token=avatar-relay-test')->assertOk();
        $this->user->forceFill(['avatar' => 'custom/u_newer.png'])->save();
        Storage::disk('public')->put('avatars/u_newer.png', $this->tinyPngBytes());

        $this->post('/api/avatar/stylize-applied', [
            'token' => 'avatar-relay-test',
            'job_id' => $job->id,
            'image' => base64_encode($this->tinyPngBytes()),
        ])->assertOk()->assertJsonPath('replaced', false);

        $this->user->refresh();
        $this->assertSame('custom/u_newer.png', $this->user->avatar);
        Storage::disk('public')->assertExists('avatars/u_newer.png');
    }

    public function test_stale_claim_fails_without_touching_avatar(): void
    {
        $job = $this->enqueue();
        $job->forceFill([
            'status' => AvatarStylizeJob::STATUS_CLAIMED,
            'claimed_at' => now()->subMinutes(5),
        ])->save();

        config(['ai_assistant.avatar.stale_seconds' => 120]);
        $this->getJson('/api/avatar/stylize-targets?token=avatar-relay-test')
            ->assertOk()
            ->assertJsonPath('count', 0);

        $this->assertSame(AvatarStylizeJob::STATUS_FAILED, $job->fresh()->status);
        $this->assertSame('custom/u_old.png', $this->user->fresh()->avatar);
    }

    public function test_new_upload_supersedes_open_job(): void
    {
        $first = $this->enqueue();
        $second = $this->enqueue();

        $this->assertSame(AvatarStylizeJob::STATUS_FAILED, $first->fresh()->status);
        $this->assertSame('superseded', $first->fresh()->last_error);
        $this->assertSame(AvatarStylizeJob::STATUS_PENDING, $second->fresh()->status);
    }

    private function enqueue(): AvatarStylizeJob
    {
        $job = app(AvatarStylizeService::class)->enqueue(
            $this->user,
            $this->tinyPngBytes(),
            'avatar_1.png',
            'club prompt',
            'blurry',
            (string) $this->user->avatar,
        );
        $this->assertNotNull($job);

        return $job;
    }
}
