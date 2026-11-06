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
use Illuminate\Support\Facades\RateLimiter;
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
            'ai_assistant.rate_limit_per_minute' => 100,
            'ai_assistant.alibaba_billing.access_key_id' => '',
            'ai_assistant.alibaba_billing.access_key_secret' => '',
            'ai_assistant.alibaba_billing.endpoint' => 'https://business.aliyuncs.com',
            'ai_assistant.yandex.billing_iam_token' => '',
            'ai_assistant.yandex.billing_account_id' => '',
        ]);

        $this->club = Club::create([
            'name' => 'Sector Test',
            'slug' => 'ai-club',
        ]);

        AiAssistantSetting::forClub($this->club->id)->forceFill([
            'is_enabled' => true,
            'llm_api_key' => 'sk-deepseek-test',
            'yandex_api_key' => 'yandex-key-test',
            'openai_api_key' => 'sk-openai-test',
            'yandex_folder_id' => 'folder-test',
        ])->save();

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

        RateLimiter::clear('shell-ai:'.$this->computer->id);
        RateLimiter::clear('shell-ai-greet:'.$this->computer->id);
        RateLimiter::clear('shell-ai-voice:'.$this->computer->id);
        $this->fakeVoiceStack();
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

        $this->fakeHttp(function ($request) {
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
        ])->assertStatus(422);

        $this->createActiveBooking();

        $this->postJson('/api/shell/ai-voice', [
            'terminal_id' => $this->computer->id,
            'tts_voice' => 'marina',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('tts_voice', 'marina')
            ->assertJsonPath('audio_base64', base64_encode('ID3marina'));

        $this->assertSame('alena', AiAssistantSetting::forClub($this->club->id)->tts_voice);
        $this->assertSame('marina', $this->user->fresh()->ttsVoice());

        $this->getJson('/api/shell/ai-voices?terminal_id='.$this->computer->id)
            ->assertOk()
            ->assertJsonPath('tts_voice', 'marina');
    }

    public function test_ai_assistant_uses_saved_player_voice(): void
    {
        $this->createActiveBooking();
        AiAssistantSetting::forClub($this->club->id)->update([
            'is_enabled' => true,
            'tts_voice' => 'alena',
        ]);
        $this->user->saveTtsVoice('zahar');
        $this->fakeVoiceStack(transcript: 'Привет', reply: 'Привет!', mp3: 'ID3voice');

        $audio = UploadedFile::fake()->create('ask.webm', 20, 'audio/webm');
        $this->post('/api/shell/ai-assistant', [
            'terminal_id' => $this->computer->id,
            'audio' => $audio,
        ])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'tts.api.cloud.yandex.net')
                && ($request['voice'] ?? null) === 'zahar';
        });
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
            ->assertJsonPath('message', 'Нет ключа DeepSeek');
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

    public function test_question_stays_on_deepseek_when_other_llm_is_openai(): void
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
            return str_contains($request->url(), 'api.deepseek.com/chat/completions')
                && ($request['model'] ?? null) === 'deepseek-chat'
                && $request->hasHeader('Authorization', 'Bearer sk-deepseek-test');
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
    }

    public function test_admin_llm_probe_returns_reply(): void
    {
        $admin = Admin::create([
            'name' => 'AI Admin',
            'email' => 'ai-admin@example.test',
            'password' => 'password',
            'role' => 'owner',
        ]);

        $this->fakeHttp(function ($request) {
            if (str_contains($request->url(), 'chat/completions')) {
                return Http::response([
                    'choices' => [['message' => ['content' => 'ок']]],
                ], 200);
            }

            return Http::response('unexpected '.$request->url(), 599);
        });

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

    public function test_admin_sees_deepseek_balance(): void
    {
        $admin = Admin::create([
            'name' => 'AI Balance',
            'email' => 'ai-balance@example.test',
            'password' => 'password',
            'role' => 'owner',
        ]);

        $this->fakeHttp(function ($request) {
            if (str_contains($request->url(), '/user/balance')) {
                return Http::response([
                    'is_available' => true,
                    'balance_infos' => [[
                        'currency' => 'CNY',
                        'total_balance' => '110.00',
                        'granted_balance' => '10.00',
                        'topped_up_balance' => '100.00',
                    ]],
                ]);
            }

            return Http::response('unexpected '.$request->url(), 599);
        });

        $this->actingAs($admin, 'admin')
            ->getJson('/admin/ai-assistant/balance?club_id='.$this->club->id)
            ->assertOk()
            ->assertJsonPath('deepseek.ok', true)
            ->assertJsonPath('deepseek.provider', 'deepseek')
            ->assertJsonPath('deepseek.is_available', true)
            ->assertJsonPath('deepseek.balances.0.currency', 'CNY')
            ->assertJsonPath('deepseek.balances.0.total', '110.00')
            ->assertJsonPath('deepseek.balances.0.granted', '10.00')
            ->assertJsonPath('deepseek.balances.0.topped_up', '100.00')
            ->assertJsonPath('qwen.ok', false)
            ->assertJsonPath('yandex.ok', false);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'https://api.deepseek.com/user/balance')
            && $request->hasHeader('Authorization', 'Bearer sk-deepseek-test'));
    }

    public function test_admin_balance_is_unsupported_for_openai(): void
    {
        AiAssistantSetting::forClub($this->club->id)->forceFill([
            'llm_provider' => 'openai',
        ])->save();

        $admin = Admin::create([
            'name' => 'AI Balance OpenAI',
            'email' => 'ai-balance-openai@example.test',
            'password' => 'password',
            'role' => 'owner',
        ]);

        $this->fakeHttp(fn () => Http::response('balance should not be called', 599));

        $this->actingAs($admin, 'admin')
            ->getJson('/admin/ai-assistant/balance?club_id='.$this->club->id)
            ->assertOk()
            ->assertJsonPath('deepseek.ok', false)
            ->assertJsonPath('deepseek.supported', false)
            ->assertJsonPath('deepseek.provider', 'openai')
            ->assertJsonPath('qwen.ok', false)
            ->assertJsonPath('yandex.ok', false);

        Http::assertNothingSent();
    }

    public function test_admin_sees_qwen_account_balance(): void
    {
        config([
            'ai_assistant.alibaba_billing.access_key_id' => 'LTAI_TEST',
            'ai_assistant.alibaba_billing.access_key_secret' => 'secret-test',
            'ai_assistant.alibaba_billing.endpoint' => 'https://business.aliyuncs.com',
        ]);

        $admin = Admin::create([
            'name' => 'AI Qwen Balance',
            'email' => 'ai-qwen-balance@example.test',
            'password' => 'password',
            'role' => 'owner',
        ]);

        $this->fakeHttp(function ($request) {
            if (str_contains($request->url(), 'business.aliyuncs.com') && str_contains($request->url(), 'QueryAccountBalance')) {
                return Http::response([
                    'Code' => '200',
                    'Success' => true,
                    'Data' => [
                        'AvailableAmount' => '26.50',
                        'AvailableCashAmount' => '20.00',
                        'CreditAmount' => '6.50',
                        'Currency' => 'CNY',
                    ],
                ]);
            }
            if (str_contains($request->url(), '/user/balance')) {
                return Http::response([
                    'is_available' => true,
                    'balance_infos' => [[
                        'currency' => 'USD',
                        'total_balance' => '1.00',
                        'granted_balance' => '0',
                        'topped_up_balance' => '1.00',
                    ]],
                ]);
            }

            return Http::response('unexpected '.$request->url(), 599);
        });

        $this->actingAs($admin, 'admin')
            ->getJson('/admin/ai-assistant/balance?club_id='.$this->club->id)
            ->assertOk()
            ->assertJsonPath('qwen.ok', true)
            ->assertJsonPath('qwen.balances.0.total', '26.50')
            ->assertJsonPath('qwen.balances.0.currency', 'CNY')
            ->assertJsonPath('qwen.balances.0.cash', '20.00')
            ->assertJsonPath('qwen.balances.0.credit', '6.50');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'https://business.aliyuncs.com/')
            && str_contains($request->url(), 'Action=QueryAccountBalance')
            && str_contains($request->url(), 'AccessKeyId=LTAI_TEST')
            && str_contains($request->url(), 'Signature='));
    }

    public function test_admin_sees_yandex_billing_balance(): void
    {
        config([
            'ai_assistant.yandex.billing_iam_token' => 'iam-test-token',
            'ai_assistant.yandex.billing_account_id' => 'dn2billing',
        ]);

        $admin = Admin::create([
            'name' => 'AI Yandex Balance',
            'email' => 'ai-yandex-balance@example.test',
            'password' => 'password',
            'role' => 'owner',
        ]);

        $this->fakeHttp(function ($request) {
            if (str_contains($request->url(), 'billing.api.cloud.yandex.net/billing/v1/billingAccounts/dn2billing')) {
                return Http::response([
                    'id' => 'dn2billing',
                    'name' => 'Клуб',
                    'currency' => 'RUB',
                    'active' => true,
                    'balance' => '840.15',
                ]);
            }
            if (str_contains($request->url(), '/user/balance')) {
                return Http::response([
                    'is_available' => true,
                    'balance_infos' => [[
                        'currency' => 'USD',
                        'total_balance' => '1.00',
                        'granted_balance' => '0',
                        'topped_up_balance' => '1.00',
                    ]],
                ]);
            }

            return Http::response('unexpected '.$request->url(), 599);
        });

        $this->actingAs($admin, 'admin')
            ->getJson('/admin/ai-assistant/balance?club_id='.$this->club->id)
            ->assertOk()
            ->assertJsonPath('yandex.ok', true)
            ->assertJsonPath('yandex.balances.0.total', '840.15')
            ->assertJsonPath('yandex.balances.0.currency', 'RUB')
            ->assertJsonPath('yandex.balances.0.name', 'Клуб');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'billingAccounts/dn2billing')
            && $request->hasHeader('Authorization', 'Bearer iam-test-token'));
    }

    public function test_admin_llm_case_probe_returns_classification(): void
    {
        $admin = Admin::create([
            'name' => 'AI Admin 2',
            'email' => 'ai-admin2@example.test',
            'password' => 'password',
            'role' => 'owner',
        ]);

        $this->fakeHttp(function ($request) {
            if (str_contains($request->url(), 'chat/completions')) {
                return Http::response([
                    'choices' => [[
                        'message' => [
                            'content' => '[{"sku":1,"color":"white","glass":"front_side","form":"atx"}]',
                        ],
                    ]],
                ], 200);
            }

            return Http::response('unexpected '.$request->url(), 599);
        });

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

        $this->fakeHttp(function ($request) {
            if (str_contains($request->url(), 'chat/completions')) {
                return Http::response([
                    'choices' => [['message' => ['content' => 'Зайди через меню в лобби.']]],
                ], 200);
            }

            return Http::response('unexpected '.$request->url(), 599);
        });

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

        $this->fakeHttp(function ($request) {
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

    public function test_qwen_voice_stack_uses_qwen_for_speech_and_reply(): void
    {
        $this->createActiveBooking();

        config([
            'ai_assistant.qwen.api_key' => 'sk-qwen-env',
            'ai_assistant.qwen.base_url' => 'https://dashscope-intl.aliyuncs.com/api/v1',
            'ai_assistant.qwen.chat_model' => 'qwen-plus',
            'ai_assistant.qwen.asr_model' => 'qwen3-asr-flash',
            'ai_assistant.qwen.tts_model' => 'qwen3-tts-flash',
            'ai_assistant.qwen.tts_voice' => 'Cherry',
            'ai_assistant.qwen.asr_language' => 'ru',
            'ai_assistant.qwen.tts_language' => 'Russian',
        ]);

        AiAssistantSetting::forClub($this->club->id)->update([
            'voice_stack' => 'qwen',
            'qwen_api_key' => 'sk-qwen-db',
            'qwen_tts_voice' => 'Cherry',
        ]);

        $this->fakeHttp(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'multimodal-generation/generation') && ($request['model'] ?? null) === 'qwen3-asr-flash') {
                return Http::response([
                    'output' => [
                        'choices' => [[
                            'message' => ['content' => [['text' => 'как зайти в игру']]],
                        ]],
                    ],
                ]);
            }
            if (str_contains($url, 'chat/completions')) {
                return Http::response([
                    'choices' => [['message' => ['content' => 'Открой библиотеку и нажми играть.']]],
                ]);
            }
            if (str_contains($url, 'multimodal-generation/generation') && ($request['model'] ?? null) === 'qwen3-tts-flash') {
                return Http::response([
                    'output' => ['audio' => ['url' => 'https://qwen-tts.example.test/out.wav']],
                ]);
            }
            if (str_contains($url, 'qwen-tts.example.test')) {
                return Http::response('RIFFwav-bytes', 200, ['Content-Type' => 'audio/wav']);
            }

            return Http::response('unexpected '.$url, 599);
        });

        $this->post('/api/shell/ai-assistant', [
            'terminal_id' => $this->computer->id,
            'audio' => UploadedFile::fake()->create('ask.wav', 20, 'audio/wav'),
        ])->assertOk()
            ->assertJsonPath('transcript', 'как зайти в игру')
            ->assertJsonPath('reply_text', 'Открой библиотеку и нажми играть.')
            ->assertJsonPath('audio_mime', 'audio/wav');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'multimodal-generation/generation')
                && ($request['model'] ?? null) === 'qwen3-asr-flash'
                && $request->hasHeader('Authorization', 'Bearer sk-qwen-db')
                && data_get($request->data(), 'parameters.asr_options.language') === 'ru';
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'compatible-mode/v1/chat/completions')
                && ($request['model'] ?? null) === 'qwen-plus'
                && $request->hasHeader('Authorization', 'Bearer sk-qwen-db')
                && ! str_contains($request->url(), 'deepseek');
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'multimodal-generation/generation')
                && ($request['model'] ?? null) === 'qwen3-tts-flash'
                && data_get($request->data(), 'input.voice') === 'Cherry'
                && data_get($request->data(), 'input.language_type') === 'Russian';
        });

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'yandex.net')
            || str_contains($request->url(), 'api.deepseek.com'));
    }

    public function test_qwen_voice_stack_without_key_is_rejected(): void
    {
        $this->createActiveBooking();

        config(['ai_assistant.qwen.api_key' => '']);
        AiAssistantSetting::forClub($this->club->id)->update([
            'voice_stack' => 'qwen',
            'qwen_api_key' => null,
        ]);

        $this->post('/api/shell/ai-assistant', [
            'terminal_id' => $this->computer->id,
            'audio' => UploadedFile::fake()->create('ask.wav', 20, 'audio/wav'),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Нет ключа Qwen');
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

    public function test_question_service_ignores_openai_speech_provider(): void
    {
        $this->createActiveBooking();

        AiAssistantSetting::forClub($this->club->id)->forceFill([
            'voice_stack' => 'deepseek_yandex',
            'speech_provider' => 'openai',
            'tts_voice' => 'nova',
            'openai_api_key' => 'sk-openai-test',
        ])->save();

        $this->fakeVoiceStack(transcript: 'привет', reply: 'хай', mp3: 'ID3oa');

        $this->post('/api/shell/ai-assistant', [
            'terminal_id' => $this->computer->id,
            'audio' => UploadedFile::fake()->create('ask.webm', 20, 'audio/webm'),
        ])->assertOk()
            ->assertJsonPath('transcript', 'привет');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'stt.api.cloud.yandex.net'));
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'tts.api.cloud.yandex.net')
                && ($request['voice'] ?? null) === 'alena';
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
    }

    /**
     * @param  mixed  $request
     */
    private function fakeVoiceStack(string $transcript = 'тест', string $reply = 'ок', string $mp3 = 'ID3'): void
    {
        $this->fakeHttp(function ($request) use ($transcript, $reply, $mp3) {
            $url = $request->url();
            if (str_contains($url, 'stt.api.cloud.yandex.net')) {
                return Http::response(['result' => $transcript], 200);
            }
            if (str_contains($url, 'tts.api.cloud.yandex.net')) {
                return Http::response($mp3, 200, ['Content-Type' => 'audio/mpeg']);
            }
            if (str_contains($url, 'audio/transcriptions')) {
                return Http::response(['text' => $transcript], 200);
            }
            if (str_contains($url, 'audio/speech')) {
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
