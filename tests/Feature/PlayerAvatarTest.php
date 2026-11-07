<?php

namespace Tests\Feature;

use App\Models\AiAssistantSetting;
use App\Models\Club;
use App\Models\User;
use App\Support\UserAvatar;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlayerAvatarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $avatarDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        RateLimiter::clear('avatar-stylize:1');

        $this->avatarDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'club-avatars-'.uniqid();
        mkdir($this->avatarDir, 0777, true);
        file_put_contents($this->avatarDir.DIRECTORY_SEPARATOR.'avatar_1.png', $this->tinyPngBytes());

        config([
            'ai_assistant.deepseek.api_key' => 'sk-deepseek-test',
            'ai_assistant.deepseek.base_url' => 'https://api.deepseek.com',
            'ai_assistant.deepseek.vision_model' => 'deepseek-flash',
            'ai_assistant.openai.api_key' => '',
            'ai_assistant.avatar_dir' => $this->avatarDir,
            'ai_assistant.avatar.qwen.api_key' => '',
            'ai_assistant.avatar.qwen.base_url' => 'https://dashscope-intl.aliyuncs.com/api/v1',
            'ai_assistant.avatar.qwen.model' => 'qwen-image-2.0-pro',
            'ai_assistant.avatar.glm.model' => 'glm-image',
            'ai_assistant.glm.api_key' => '',
            'ai_assistant.glm.base_url' => 'https://api.z.ai/api/paas/v4',
            'ai_assistant.avatar.comfyui.url' => '',
        ]);

        Club::create([
            'name' => 'Avatar Club',
            'slug' => 'avatar-club',
            'type' => 'club',
        ]);

        $this->user = User::create([
            'name' => 'AvatarUser',
            'phone' => '+79990001100',
            'email' => 'avatar@example.test',
            'password' => 'password',
            'avatar' => 'avatar_1.png',
        ]);
        RateLimiter::clear('avatar-stylize:'.$this->user->id);
    }

    protected function tearDown(): void
    {
        $file = $this->avatarDir.DIRECTORY_SEPARATOR.'avatar_1.png';
        if (is_file($file)) {
            @unlink($file);
        }
        if (is_dir($this->avatarDir)) {
            @rmdir($this->avatarDir);
        }

        parent::tearDown();
    }

    public function test_guest_cannot_upload_avatar(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/account/profile/avatar', [
                'photo' => $this->fakeImageUpload('face.png'),
            ])
            ->assertRedirect();
    }

    public function test_player_can_upload_own_photo_without_stylize(): void
    {
        Http::fake();

        $this->actingAs($this->user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/account/profile/avatar', [
                'photo' => $this->fakeImageUpload('face.png'),
                'stylize' => 0,
            ])
            ->assertRedirect();

        $this->user->refresh();
        $this->assertTrue(UserAvatar::isCustom($this->user->avatar));
        $this->assertStringStartsWith('custom/u'.$this->user->id.'_', $this->user->avatar);
        Storage::disk('public')->assertExists('avatars/'.basename($this->user->avatar));
        $this->assertStringContainsString('/storage/avatars/', $this->user->avatar_url);
        Http::assertNothingSent();
    }

    public function test_stylize_without_qwen_key_keeps_current_avatar(): void
    {
        Http::fake();

        $this->actingAs($this->user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/account/profile')
            ->post('/account/profile/avatar', [
                'photo' => $this->fakeImageUpload('face.png'),
                'stylize' => 1,
                'sample' => 'avatar_1.png',
            ])
            ->assertRedirect('/account/profile')
            ->assertSessionHasErrors('photo');

        $this->user->refresh();
        $this->assertSame('avatar_1.png', $this->user->avatar);
        $this->assertDatabaseCount('avatar_stylize_jobs', 0);
        Http::assertNothingSent();
    }

    public function test_stylize_sends_photo_and_sample_to_qwen(): void
    {
        config(['ai_assistant.avatar.qwen.api_key' => 'sk-qwen-test']);
        $styled = $this->redFacePng();
        $sent = null;

        $this->fakeHttp(function ($request) use (&$sent, $styled) {
            if (str_contains($request->url(), 'multimodal-generation')) {
                $sent = $request->data();

                return Http::response([
                    'output' => [
                        'choices' => [[
                            'message' => [
                                'content' => [
                                    ['image' => 'https://qwen-result.example.test/out.png'],
                                ],
                            ],
                        ]],
                    ],
                ]);
            }
            if (str_contains($request->url(), 'qwen-result.example.test')) {
                return Http::response($styled, 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('unexpected '.$request->url(), 599);
        });

        $this->actingAs($this->user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/account/profile/avatar', [
                'photo' => $this->fakeImageUpload('face.png'),
                'stylize' => 1,
                'sample' => 'avatar_1.png',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->user->refresh();
        $this->assertTrue(UserAvatar::isCustom($this->user->avatar));
        $stored = Storage::disk('public')->get('avatars/'.basename($this->user->avatar));
        $this->assertIsString($stored);
        $img = imagecreatefromstring($stored);
        $this->assertNotFalse($img);
        $pixel = imagecolorat($img, 256, 256);
        $this->assertGreaterThan(180, ($pixel >> 16) & 0xFF);
        $this->assertLessThan(80, $pixel & 0xFF);
        imagedestroy($img);

        $this->assertIsArray($sent);
        $this->assertSame('qwen-image-2.0-pro', $sent['model'] ?? null);
        $content = $sent['input']['messages'][0]['content'] ?? [];
        $this->assertCount(3, $content);
        $this->assertStringStartsWith('data:image/', (string) ($content[0]['image'] ?? ''));
        $this->assertStringStartsWith('data:image/', (string) ($content[1]['image'] ?? ''));
        $this->assertStringContainsString('Image 1', (string) ($content[2]['text'] ?? ''));
        $this->assertStringContainsString('Image 2', (string) ($content[2]['text'] ?? ''));
        $this->assertSame('1024*1024', $sent['parameters']['size'] ?? null);
        $this->assertFalse($sent['parameters']['watermark'] ?? true);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'multimodal-generation')
            && $request->hasHeader('Authorization', 'Bearer sk-qwen-test'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'deepseek')
            || str_contains($request->url(), '8188'));
        $this->assertDatabaseCount('avatar_stylize_jobs', 0);
    }

    public function test_stylize_sends_photo_and_sample_to_glm_when_selected(): void
    {
        config(['ai_assistant.glm.api_key' => 'glm-image-test']);
        AiAssistantSetting::forClub(Club::query()->value('id'))->update([
            'avatar_provider' => 'glm',
        ]);
        $styled = $this->redFacePng();
        $sent = null;

        $this->fakeHttp(function ($request) use (&$sent, $styled) {
            if (str_contains($request->url(), 'images/generations')) {
                $sent = $request->data();

                return Http::response([
                    'data' => [['url' => 'https://glm-result.example.test/out.png']],
                ]);
            }
            if (str_contains($request->url(), 'glm-result.example.test')) {
                return Http::response($styled, 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('unexpected '.$request->url(), 599);
        });

        $this->actingAs($this->user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/account/profile/avatar', [
                'photo' => $this->fakeImageUpload('face.png'),
                'stylize' => 1,
                'sample' => 'avatar_1.png',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertIsArray($sent);
        $this->assertSame('glm-image', $sent['model'] ?? null);
        $this->assertSame('1280x1280', $sent['size'] ?? null);
        $this->assertCount(2, $sent['images'] ?? []);
        $this->assertStringStartsWith('data:image/', (string) ($sent['images'][0] ?? ''));
        $this->assertStringStartsWith('data:image/', (string) ($sent['images'][1] ?? ''));
        $this->assertStringContainsString('Image 1', (string) ($sent['prompt'] ?? ''));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.z.ai/api/paas/v4/images/generations')
            && $request->hasHeader('Authorization', 'Bearer glm-image-test'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'dashscope')
            || str_contains($request->url(), 'multimodal-generation'));
    }

    public function test_stylize_failure_does_not_replace_avatar(): void
    {
        config(['ai_assistant.avatar.qwen.api_key' => 'sk-qwen-test']);
        $this->fakeHttp(fn () => Http::response(['code' => 'Throttling', 'message' => 'busy'], 429));

        $this->actingAs($this->user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/account/profile')
            ->post('/account/profile/avatar', [
                'photo' => $this->fakeImageUpload('face.png'),
                'stylize' => 1,
                'sample' => 'avatar_1.png',
            ])
            ->assertRedirect('/account/profile')
            ->assertSessionHasErrors('photo');

        $this->user->refresh();
        $this->assertSame('avatar_1.png', $this->user->avatar);
        $this->assertDatabaseCount('avatar_stylize_jobs', 0);
    }

    public function test_stylize_rejects_unknown_sample(): void
    {
        config(['ai_assistant.avatar.qwen.api_key' => 'sk-qwen-test']);
        Http::fake();

        $this->actingAs($this->user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/account/profile')
            ->post('/account/profile/avatar', [
                'photo' => $this->fakeImageUpload('face.png'),
                'stylize' => 1,
                'sample' => 'avatar_99.png',
            ])
            ->assertRedirect('/account/profile')
            ->assertSessionHasErrors('sample');

        $this->user->refresh();
        $this->assertSame('avatar_1.png', $this->user->avatar);
        Http::assertNothingSent();
    }

    public function test_rejects_non_image_upload(): void
    {
        $this->actingAs($this->user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/account/profile')
            ->post('/account/profile/avatar', [
                'photo' => UploadedFile::fake()->createWithContent('notes.txt', 'not an image'),
            ])
            ->assertRedirect('/account/profile')
            ->assertSessionHasErrors('photo');

        $this->user->refresh();
        $this->assertSame('avatar_1.png', $this->user->avatar);
    }

    private function redFacePng(): string
    {
        $size = 512;
        $im = imagecreatetruecolor($size, $size);
        imagefilledrectangle($im, 0, 0, $size, $size, imagecolorallocate($im, 220, 48, 42));
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }
}
