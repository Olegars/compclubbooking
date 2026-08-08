<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Computer $computer;

    private User $user;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai_assistant.enabled' => true,
            'ai_assistant.deepseek.api_key' => 'sk-deepseek-test',
            'ai_assistant.deepseek.base_url' => 'https://api.deepseek.com',
            'ai_assistant.deepseek.model' => 'deepseek-chat',
            'ai_assistant.openai.api_key' => 'sk-openai-test',
            'ai_assistant.openai.base_url' => 'https://api.openai.com/v1',
            'ai_assistant.openai.stt_model' => 'whisper-1',
            'ai_assistant.openai.tts_model' => 'tts-1',
            'ai_assistant.openai.tts_voice' => 'nova',
            'ai_assistant.rate_limit_per_minute' => 20,
        ]);

        $this->club = Club::create([
            'name' => 'Sector Test',
            'slug' => 'ai-club',
        ]);

        $this->computer = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-AI',
            'status' => 'busy',
        ]);

        $this->user = User::create([
            'name' => 'Gamer',
            'phone' => '+79991234567',
            'email' => 'ai@example.test',
            'password' => 'password',
        ]);

        $this->game = Game::create([
            'title' => 'Counter-Strike 2',
            'platform' => 'PC',
            'category' => 'STEAM',
        ]);
    }

    public function test_requires_active_session(): void
    {
        $audio = UploadedFile::fake()->create('ask.webm', 20, 'audio/webm');

        $this->post('/api/shell/ai-assistant', [
            'terminal_id' => $this->computer->id,
            'audio' => $audio,
            'game_id' => $this->game->id,
        ])->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_full_pipeline_returns_audio_and_text(): void
    {
        Booking::create([
            'user_id' => $this->user->id,
            'computer_id' => $this->computer->id,
            'pc_ids' => [(string) $this->computer->id],
            'date' => now()->toDateString(),
            'start_time' => 12.0,
            'duration' => 2,
            'price' => 100,
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
        ]);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Как кинуть смок на мид?',
            ], 200),
            'api.deepseek.com/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Кидай с тетриса в щель у контейнеров.']],
                ],
            ], 200),
            'api.openai.com/v1/audio/speech' => Http::response('ID3fake-mp3-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $audio = UploadedFile::fake()->create('ask.webm', 20, 'audio/webm');

        $response = $this->post('/api/shell/ai-assistant', [
            'terminal_id' => $this->computer->id,
            'audio' => $audio,
            'game_id' => $this->game->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('transcript', 'Как кинуть смок на мид?')
            ->assertJsonPath('reply_text', 'Кидай с тетриса в щель у контейнеров.')
            ->assertJsonPath('game_title', 'Counter-Strike 2')
            ->assertJsonPath('audio_mime', 'audio/mpeg');

        $this->assertNotEmpty($response->json('audio_base64'));
        $this->assertSame(
            'ID3fake-mp3-bytes',
            base64_decode((string) $response->json('audio_base64'))
        );
    }

    public function test_uses_custom_tts_voice_and_companion_prompt(): void
    {
        Booking::create([
            'user_id' => $this->user->id,
            'computer_id' => $this->computer->id,
            'pc_ids' => [(string) $this->computer->id],
            'date' => now()->toDateString(),
            'start_time' => 12.0,
            'duration' => 2,
            'price' => 100,
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
        ]);

        \App\Models\AiAssistantSetting::forClub($this->club->id)->update([
            'is_enabled' => true,
            'tts_voice' => 'onyx',
            'companion_prompt' => 'CUSTOM_COMPANION for {{player}} in {{game}} club {{club}} max {{max_chars}}',
        ]);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Привет',
            ], 200),
            'api.deepseek.com/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Привет!']],
                ],
            ], 200),
            'api.openai.com/v1/audio/speech' => Http::response('ID3voice', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $audio = UploadedFile::fake()->create('ask.webm', 20, 'audio/webm');

        $this->post('/api/shell/ai-assistant', [
            'terminal_id' => $this->computer->id,
            'audio' => $audio,
            'game_id' => $this->game->id,
        ])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }
            $messages = $request['messages'] ?? [];
            $system = (string) data_get($messages, '0.content', '');

            return str_contains($system, 'CUSTOM_COMPANION')
                && str_contains($system, 'Gamer')
                && str_contains($system, 'Counter-Strike 2')
                && str_contains($system, 'Sector Test');
        });

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'audio/speech')) {
                return false;
            }

            return ($request['voice'] ?? null) === 'onyx';
        });
    }

    public function test_disabled_in_admin_settings(): void
    {
        \App\Models\AiAssistantSetting::forClub($this->club->id)->update([
            'is_enabled' => false,
        ]);

        Booking::create([
            'user_id' => $this->user->id,
            'computer_id' => $this->computer->id,
            'pc_ids' => [(string) $this->computer->id],
            'date' => now()->toDateString(),
            'start_time' => 12.0,
            'duration' => 2,
            'price' => 100,
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
        ]);

        $audio = UploadedFile::fake()->create('ask.webm', 20, 'audio/webm');

        $this->post('/api/shell/ai-assistant', [
            'terminal_id' => $this->computer->id,
            'audio' => $audio,
        ])->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_disabled_when_not_configured(): void
    {
        config(['ai_assistant.enabled' => false]);

        Booking::create([
            'user_id' => $this->user->id,
            'computer_id' => $this->computer->id,
            'pc_ids' => [(string) $this->computer->id],
            'date' => now()->toDateString(),
            'start_time' => 12.0,
            'duration' => 2,
            'price' => 100,
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
        ]);

        $audio = UploadedFile::fake()->create('ask.webm', 20, 'audio/webm');

        $this->post('/api/shell/ai-assistant', [
            'terminal_id' => $this->computer->id,
            'audio' => $audio,
        ])->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }
}
