<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Game;
use App\Models\User;
use App\Models\UserGameStat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceGreetingTest extends TestCase
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
            'ai_assistant.openai.tts_model' => 'tts-1',
            'ai_assistant.openai.tts_voice' => 'nova',
            'ai_assistant.rate_limit_per_minute' => 20,
        ]);

        $this->club = Club::create([
            'name' => 'Sector Test',
            'slug' => 'greet-club',
        ]);

        $this->computer = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-GREET',
            'status' => 'busy',
        ]);

        $this->user = User::create([
            'name' => 'Alex',
            'phone' => '+79991112233',
            'email' => 'greet@example.test',
            'password' => 'password',
        ]);

        $this->game = Game::create([
            'title' => 'Dota 2',
            'platform' => 'PC',
            'category' => 'STEAM',
        ]);
    }

    public function test_requires_active_session(): void
    {
        $this->postJson('/api/shell/voice-greeting', [
            'terminal_id' => $this->computer->id,
        ])->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_first_visit_greeting_pipeline(): void
    {
        $booking = Booking::create([
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
            'actual_started_at' => now()->subMinute(),
        ]);

        UserGameStat::create([
            'user_id' => $this->user->id,
            'game_id' => $this->game->id,
            'launch_count' => 7,
            'last_launched_at' => now()->subDay(),
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'chat/completions')) {
                return Http::response([
                    'choices' => [
                        ['message' => ['content' => 'Привет, Alex! Рады видеть тебя в Sector Test.']],
                    ],
                ], 200);
            }
            if (str_contains($url, 'tts.api.cloud.yandex.net')) {
                return Http::response('ID3greet-mp3', 200, ['Content-Type' => 'audio/mpeg']);
            }

            return Http::response('unexpected '.$url, 599);
        });

        $response = $this->postJson('/api/shell/voice-greeting', [
            'terminal_id' => $this->computer->id,
            'booking_id' => $booking->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('is_first_visit', true)
            ->assertJsonPath('visit_count_completed', 0)
            ->assertJsonPath('reply_text', 'Привет, Alex! Рады видеть тебя в Sector Test.')
            ->assertJsonPath('audio_mime', 'audio/mpeg')
            ->assertJsonPath('favorite_games.0.title', 'Dota 2')
            ->assertJsonPath('context.player_name', 'Alex');

        $this->assertSame(
            'ID3greet-mp3',
            base64_decode((string) $response->json('audio_base64'))
        );
    }

    public function test_uses_custom_greeting_prompt_and_voice(): void
    {
        $booking = Booking::create([
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
            'actual_started_at' => now()->subMinute(),
        ]);

        \App\Models\AiAssistantSetting::forClub($this->club->id)->update([
            'tts_voice' => 'marina',
            'greeting_prompt' => 'CUSTOM_GREET {{player}} on {{pc}} at {{club}} games {{games}} {{visit_line}} time {{time}} max {{max_chars}}',
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'chat/completions')) {
                return Http::response([
                    'choices' => [
                        ['message' => ['content' => 'Кастомное приветствие.']],
                    ],
                ], 200);
            }
            if (str_contains($url, 'tts.api.cloud.yandex.net')) {
                return Http::response('ID3greet-mp3', 200);
            }

            return Http::response('unexpected '.$url, 599);
        });

        $this->postJson('/api/shell/voice-greeting', [
            'terminal_id' => $this->computer->id,
            'booking_id' => $booking->id,
        ])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }
            $system = (string) data_get($request['messages'] ?? [], '0.content', '');

            return str_contains($system, 'CUSTOM_GREET')
                && str_contains($system, 'Alex')
                && str_contains($system, 'PC-GREET')
                && str_contains($system, 'Sector Test');
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'tts.api.cloud.yandex.net')
                && ($request['voice'] ?? null) === 'marina';
        });
    }

    public function test_returning_visit_flag(): void
    {
        Booking::create([
            'user_id' => $this->user->id,
            'computer_id' => $this->computer->id,
            'pc_ids' => [(string) $this->computer->id],
            'date' => now()->subDay()->toDateString(),
            'start_time' => 10.0,
            'duration' => 1,
            'price' => 50,
            'status' => 'completed',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addHour(),
            'actual_started_at' => now()->subDay(),
            'actual_ended_at' => now()->subDay()->addHour(),
        ]);

        $booking = Booking::create([
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
            'actual_started_at' => now()->subMinute(),
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'chat/completions')) {
                return Http::response([
                    'choices' => [
                        ['message' => ['content' => 'С возвращением, Alex!']],
                    ],
                ], 200);
            }
            if (str_contains($url, 'tts.api.cloud.yandex.net')) {
                return Http::response('ID3greet-mp3', 200);
            }

            return Http::response('unexpected '.$url, 599);
        });

        $this->postJson('/api/shell/voice-greeting', [
            'terminal_id' => $this->computer->id,
            'booking_id' => $booking->id,
        ])->assertOk()
            ->assertJsonPath('is_first_visit', false)
            ->assertJsonPath('visit_count_completed', 1);
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

        $this->postJson('/api/shell/voice-greeting', [
            'terminal_id' => $this->computer->id,
        ])->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Нет ключа LLM (DeepSeek/OpenAI)');
    }
}
