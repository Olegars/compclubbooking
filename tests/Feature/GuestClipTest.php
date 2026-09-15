<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\GuestClip;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GuestClipTest extends TestCase
{
    use RefreshDatabase;

    public function test_shell_uploads_clip_into_guest_profile(): void
    {
        Storage::fake('public');

        $user = User::create([
            'name' => 'Clip User',
            'phone' => '+79990001122',
            'email' => 'clip@example.test',
            'password' => 'password',
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'deposit_balance' => 100,
            'bonus_balance' => 0,
            'total_spent' => 0,
        ]);
        $club = Club::create(['name' => 'Clip Club', 'slug' => 'clip-club']);
        $pc = Computer::create([
            'club_id' => $club->id,
            'name' => 'PC-12',
            'status' => 'available',
            'kind' => 'pc',
        ]);
        Booking::create([
            'user_id' => $user->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => now()->toDateString(),
            'start_time' => 12,
            'duration' => 2,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'active',
            'pin_code' => '1234',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $file = UploadedFile::fake()->create('highlight.mp4', 200, 'video/mp4');

        $this->post('/api/shell/clips', [
            'terminal_id' => $pc->id,
            'duration_sec' => 60,
            'aspect' => '9:16',
            'source' => 'kill',
            'share_token' => 'cinematicclipsharetoken123456789ab',
            'clip' => $file,
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('clip.aspect', '9:16')
            ->assertJsonPath('clip.share_url', url('/clips/cinematicclipsharetoken123456789ab'));

        $clip = GuestClip::query()->first();
        $this->assertNotNull($clip);
        $this->assertSame($user->id, (int) $clip->user_id);
        $this->assertSame('9:16', $clip->aspect);
        $this->assertSame('kill', $clip->source);
        $this->assertSame('cinematicclipsharetoken123456789ab', $clip->share_token);
        Storage::disk('public')->assertExists($clip->path);

        $this->get('/clips/'.$clip->share_token)->assertOk();

        $this->actingAs($user)
            ->get('/account/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('User/Dashboard')
                ->has('clips', 1)
            );
    }

    public function test_telegram_share_posts_when_bot_configured(): void
    {
        Storage::fake('public');
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.clips_chat_id' => '-1001',
        ]);

        $user = User::create([
            'name' => 'Clip User',
            'phone' => '+79990001123',
            'email' => 'clip2@example.test',
            'password' => 'password',
        ]);
        Storage::disk('public')->put('clips/1/a.mp4', 'fake-mp4');
        $clip = GuestClip::query()->create([
            'user_id' => $user->id,
            'path' => 'clips/1/a.mp4',
            'bytes' => 8,
            'duration_sec' => 60,
            'share_token' => 'sharetokenabcdefghijklmnopqrstuv',
        ]);

        $this->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/account/clips/'.$clip->id.'/telegram')
            ->assertRedirect();

        $this->assertNotNull($clip->fresh()->telegram_sent_at);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendVideo'));
    }
}
