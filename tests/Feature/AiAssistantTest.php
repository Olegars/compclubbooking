<?php

namespace Tests\Feature;

use App\Models\AiAssistantSetting;
use App\Models\Admin;
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
            'ai_assistant.speech_provider' => 'yandex',
            'ai_assistant.deepseek.api_key' => 'sk-deepseek-test',
            'ai_assistant.deepseek.base_url' => 'https://api.deepseek.com',
            'ai_assistant.deepseek.model' => 'deepseek-chat',
            'ai_assistant.yandex.api_key' => 'yandex-key-test',
            'ai_assistant.yandex.folder_id' => 'folder-test',
            'ai_assistant.yandex.tts_voice' => 'alena',
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

        $this->fakeVoiceStack(
            transcript: 'Как кинуть смок на мид?',
            reply: 'Кидай с тетриса в щель у контейнеров.',
            mp3: 'ID3fake-mp3-bytes',
        );

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

        AiAssistantSetting::forClub($this->club->id)->update([
            'is_enabled' => true,
            'tts_voice' => 'filipp',
            'companion_prompt' => 'CUSTOM_COMPANION for {{player}} in {{game}} club {{club}} max {{max_chars}}',
        ]);

        $this->fakeVoiceStack(transcript: 'Привет', reply: 'Привет!', mp3: 'ID3voice');

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
            return str_contains($request->url(), 'tts.api.cloud.yandex.net')
                && ($request['voice'] ?? null) === 'filipp';
        });
    }

    public function test_shell_lists_and_saves_tts_voice_with_preview(): void
    {
        AiAssistantSetting::forClub($this->club->id)->update([
            'is_enabled' => true,
            'tts_voice' => 'alena',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'tts.api.cloud.yandex.net')) {
                return Http::response('ID3marina', 200, ['Content-Type' => 'audio/mpeg']);
            }

            return Http::response('unexpected', 599);
        });

        $this->getJson('/api/shell/ai-voices?terminal_id='.$this->computer->id)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('tts_voice', 'alena')
            ->assertJsonPath('provider', 'yandex')
            ->assertJsonFragment(['id' => 'marina', 'label' => 'Марина']);

        $this->postJson('/api/shell/ai-voice', [
            'terminal_id' => $this->computer->id,
            'tts_voice' => 'marina',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('tts_voice', 'marina')
            ->assertJsonPath('audio_base64', base64_encode('ID3marina'));

        $this->assertSame('marina', AiAssistantSetting::forClub($this->club->id)->tts_voice);
    }

    public function test_ai_assistant_accepts_tts_voice_override(): void
    {
        $this->createActiveBooking();
        AiAssistantSetting::forClub($this->club->id)->update([
            'is_enabled' => true,
            'tts_voice' => 'alena',
        ]);
        $this->fakeVoiceStack(transcript: 'Привет', reply: 'Привет!', mp3: 'ID3voice');

        $audio = UploadedFile::fake()->create('ask.webm', 20, 'audio/webm');
        $this->post('/api/shell/ai-assistant', [
            'terminal_id' => $this->computer->id,
            'audio' => $audio,
            'tts_voice' => 'jane',
        ])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'tts.api.cloud.yandex.net')
                && ($request['voice'] ?? null) === 'jane';
        });
    }

    public function test_disabled_in_admin_settings(): void
    {
        AiAssistantSetting::forClub($this->club->id)->update([
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
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Выключен тумблером в админке');
    }

    public function test_disabled_when_missing_credentials(): void
    {
        config([
            'ai_assistant.deepseek.api_key' => '',
            'ai_assistant.yandex.api_key' => '',
            'ai_assistant.openai.api_key' => '',
        ]);
        AiAssistantSetting::forClub($this->club->id)->update([
            'llm_api_key' => null,
            'yandex_api_key' => null,
            'openai_api_key' => null,
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
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Нет ключа LLM (DeepSeek/OpenAI)');
    }

    public function test_uses_api_keys_from_database(): void
    {
        $this->createActiveBooking();

        AiAssistantSetting::forClub($this->club->id)->update([
            'llm_api_key' => 'sk-db-llm',
            'yandex_api_key' => 'yk-db',
            'yandex_folder_id' => 'folder-db',
            'llm_provider' => 'deepseek',
        ]);

        $this->fakeVoiceStack();

        $this->post('/api/shell/ai-assistant', [
            'terminal_id' => $this->computer->id,
            'audio' => UploadedFile::fake()->create('ask.webm', 20, 'audio/webm'),
            'game_id' => $this->game->id,
        ])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'chat/completions')
                && $request->hasHeader('Authorization', 'Bearer sk-db-llm');
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'stt.api.cloud.yandex.net')
                && $request->hasHeader('Authorization', 'Api-Key yk-db');
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'tts.api.cloud.yandex.net')
                && $request->hasHeader('Authorization', 'Api-Key yk-db');
        });
    }

    public function test_cleared_db_key_falls_back_to_env(): void
    {
        $this->createActiveBooking();

        $settings = AiAssistantSetting::forClub($this->club->id);
        $settings->update([
            'llm_api_key' => 'sk-db-llm',
            'yandex_api_key' => 'yk-db',
            'openai_api_key' => 'sk-db-openai',
        ]);
        $settings->update([
            'llm_api_key' => null,
            'yandex_api_key' => null,
            'openai_api_key' => null,
        ]);

        $this->assertSame('env', $settings->fresh()->llmKeySource());
        $this->assertSame('sk-deepseek-test', $settings->fresh()->resolvedLlmApiKey());
        $this->assertSame('yandex-key-test', $settings->fresh()->resolvedYandexApiKey());
        $this->assertSame('sk-openai-test', $settings->fresh()->resolvedOpenAiApiKey());

        $this->fakeVoiceStack();

        $this->post('/api/shell/ai-assistant', [
            'terminal_id' => $this->computer->id,
            'audio' => UploadedFile::fake()->create('ask.webm', 20, 'audio/webm'),
        ])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'chat/completions')
                && $request->hasHeader('Authorization', 'Bearer sk-deepseek-test');
        });
    }

    public function test_openai_llm_provider_uses_openai_chat_endpoint(): void
    {
        $this->createActiveBooking();

        AiAssistantSetting::forClub($this->club->id)->update([
            'llm_provider' => 'openai',
            'llm_api_key' => 'sk-openai-llm',
            'llm_base_url' => null,
            'llm_model' => null,
            'openai_api_key' => 'sk-db-openai',
        ]);

        $this->fakeVoiceStack(transcript: 'тест', reply: 'ответ openai');

        $this->post('/api/shell/ai-assistant', [
            'terminal_id' => $this->computer->id,
            'audio' => UploadedFile::fake()->create('ask.webm', 20, 'audio/webm'),
        ])->assertOk()
            ->assertJsonPath('reply_text', 'ответ openai');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.openai.com/v1/chat/completions')
                && ($request['model'] ?? null) === 'gpt-4o-mini'
                && $request->hasHeader('Authorization', 'Bearer sk-openai-llm');
        });
    }

    public function test_admin_llm_probe_returns_reply(): void
    {
        $admin = Admin::create([
            'name' => 'AI Admin',
            'email' => 'ai-admin@example.test',
            'password' => 'password',
            'role' => 'owner',
        ]);

        Http::fake([
            'api.deepseek.com/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'ок']]],
            ], 200),
        ]);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->postJson('/admin/ai-assistant/test-llm', ['club_id' => $this->club->id])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reply', 'ок')
            ->assertJsonPath('provider', 'deepseek');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'chat/completions')
                && $request->hasHeader('Authorization', 'Bearer sk-deepseek-test')
                && ($request['max_tokens'] ?? null) === 64
                && data_get($request, 'thinking.type') === 'disabled';
        });
    }

    public function test_admin_llm_case_probe_returns_classification(): void
    {
        $admin = Admin::create([
            'name' => 'AI Admin 2',
            'email' => 'ai-admin2@example.test',
            'password' => 'password',
            'role' => 'owner',
        ]);

        Http::fake([
            'api.deepseek.com/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '[{"sku":1,"color":"white","glass":"front_side","form":"atx"}]',
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->postJson('/admin/ai-assistant/test-llm', [
                'club_id' => $this->club->id,
                'case_name' => 'Корпус DeepCool CH560 Digital WH White TG',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('color', 'white')
            ->assertJsonPath('glass', 'front_side')
            ->assertJsonPath('form', 'atx');
    }

    public function test_admin_question_probe_uses_companion_prompt(): void
    {
        $admin = Admin::create([
            'name' => 'AI Question Admin',
            'email' => 'ai-q@example.test',
            'password' => 'password',
            'role' => 'owner',
        ]);

        Http::fake([
            'api.deepseek.com/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Зайди через меню в лобби.']]],
            ], 200),
        ]);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->postJson('/admin/ai-assistant/test-question', [
                'club_id' => $this->club->id,
                'question' => 'Как зайти в игру?',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reply', 'Зайди через меню в лобби.')
            ->assertJsonPath('provider', 'deepseek');

        Http::assertSent(function ($request) {
            $messages = $request['messages'] ?? [];
            $system = (string) data_get($messages, '0.content', '');
            $user = (string) data_get($messages, '1.content', '');

            return str_contains($request->url(), 'chat/completions')
                && $user === 'Как зайти в игру?'
                && str_contains($system, 'голосовой компаньон')
                && data_get($request, 'thinking.type') === 'disabled';
        });
    }

    public function test_admin_tts_probe_returns_audio(): void
    {
        $admin = Admin::create([
            'name' => 'AI TTS Admin',
            'email' => 'ai-tts@example.test',
            'password' => 'password',
            'role' => 'owner',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'tts.api.cloud.yandex.net')) {
                return Http::response('ID3tts-bytes', 200, [
                    'Content-Type' => 'audio/mpeg',
                ]);
            }

            return Http::response('unexpected', 599);
        });

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->postJson('/admin/ai-assistant/test-tts', [
                'club_id' => $this->club->id,
                'text' => 'Проверка',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('provider', 'yandex')
            ->assertJsonPath('voice', 'alena')
            ->assertJsonPath('audio_base64', base64_encode('ID3tts-bytes'));
    }

    public function test_greeting_prompt_includes_favorite_games(): void
    {
        $settings = AiAssistantSetting::forClub($this->club->id);
        $prompt = $settings->resolveGreetingPrompt([
            'player_name' => 'Иван',
            'club_name' => 'Club',
            'pc_name' => 'PC-1',
            'time_remaining' => '01:00:00',
            'is_first_visit' => false,
            'visit_count_completed' => 3,
            'favorite_games' => [['id' => 1, 'title' => 'CS2', 'launch_count' => 9]],
        ], 200);

        $this->assertStringContainsString('CS2 (9)', $prompt);
    }

    public function test_openai_speech_provider_uses_whisper_and_tts(): void
    {
        $this->createActiveBooking();

        AiAssistantSetting::forClub($this->club->id)->update([
            'speech_provider' => 'openai',
            'tts_voice' => 'nova',
        ]);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'привет'], 200),
            'api.deepseek.com/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'хай']]],
            ], 200),
            'api.openai.com/v1/audio/speech' => Http::response('ID3oa', 200),
        ]);

        $this->post('/api/shell/ai-assistant', [
            'terminal_id' => $this->computer->id,
            'audio' => UploadedFile::fake()->create('ask.webm', 20, 'audio/webm'),
        ])->assertOk()
            ->assertJsonPath('transcript', 'привет');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'audio/transcriptions');
        });
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'audio/speech')
                && ($request['voice'] ?? null) === 'nova';
        });
    }

    /**
     * @param  mixed  $request
     */
    private function fakeVoiceStack(string $transcript = 'тест', string $reply = 'ок', string $mp3 = 'ID3'): void
    {
        Http::fake(function ($request) use ($transcript, $reply, $mp3) {
            $url = $request->url();
            if (str_contains($url, 'stt.api.cloud.yandex.net')) {
                return Http::response(['result' => $transcript], 200);
            }
            if (str_contains($url, 'tts.api.cloud.yandex.net')) {
                return Http::response($mp3, 200, ['Content-Type' => 'audio/mpeg']);
            }
            if (str_contains($url, 'chat/completions')) {
                return Http::response([
                    'choices' => [['message' => ['content' => $reply]]],
                ], 200);
            }

            return Http::response('unexpected '.$url, 599);
        });
    }

    private function createActiveBooking(): void
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
    }
}
