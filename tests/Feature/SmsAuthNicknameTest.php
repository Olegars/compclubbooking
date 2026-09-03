<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsAuthNicknameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai_assistant.deepseek.api_key' => 'sk-deepseek-test',
            'ai_assistant.deepseek.base_url' => 'https://api.deepseek.com',
            'ai_assistant.deepseek.model' => 'deepseek-chat',
        ]);
    }

    public function test_new_user_gets_deepseek_nick_without_underscores_or_hyphens(): void
    {
        Http::fake([
            'api.deepseek.com/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'FrostFox']]],
            ], 200),
        ]);

        $this->post('/auth/verify-code', [
            'phone' => '+79991112233',
            'code' => '0451',
        ])->assertRedirect();

        $user = User::query()->where('phone', '+79991112233')->first();
        $this->assertNotNull($user);
        $this->assertSame('FrostFox', $user->name);
        $this->assertDoesNotMatchRegularExpression('/[_-]/', $user->name);

        Http::assertSent(function ($request) {
            $system = (string) data_get($request, 'messages.0.content');

            return str_contains($request->url(), 'chat/completions')
                && str_contains($system, 'подчёркиваний')
                && data_get($request, 'thinking.type') === 'disabled'
                && $request->hasHeader('Authorization', 'Bearer sk-deepseek-test');
        });
    }

    public function test_strips_punctuation_and_separators_from_llm_nick(): void
    {
        Http::fake([
            'api.deepseek.com/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => "Ник: Frost-Fox_\nГотово"]]],
            ], 200),
        ]);

        $this->post('/auth/verify-code', [
            'phone' => '+79991112234',
            'code' => '0451',
        ])->assertRedirect();

        $this->assertSame(
            'FrostFox',
            User::query()->where('phone', '+79991112234')->value('name')
        );
    }

    public function test_falls_back_to_pronounceable_pool_when_llm_fails(): void
    {
        Http::fake([
            'api.deepseek.com/chat/completions' => Http::response('down', 503),
        ]);

        $this->post('/auth/verify-code', [
            'phone' => '+79991112235',
            'code' => '0451',
        ])->assertRedirect();

        $name = (string) User::query()->where('phone', '+79991112235')->value('name');
        $this->assertNotSame('', $name);
        $this->assertDoesNotMatchRegularExpression('/[_-]/', $name);
        $this->assertDoesNotMatchRegularExpression('/^Stalker/i', $name);
        $this->assertMatchesRegularExpression('/^[A-Za-zА-Яа-яЁё]+[0-9]*$/u', $name);
    }

    public function test_existing_user_keeps_name(): void
    {
        Http::fake();

        $user = User::create([
            'name' => 'OldNick',
            'phone' => '+79991112236',
            'email' => 'old@example.test',
            'password' => 'password',
        ]);

        $this->post('/auth/verify-code', [
            'phone' => $user->phone,
            'code' => '0451',
        ])->assertRedirect();

        $this->assertSame('OldNick', $user->fresh()->name);
        Http::assertNothingSent();
    }
}
