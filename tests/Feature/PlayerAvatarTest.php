<?php

namespace Tests\Feature;

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
        RateLimiter::clear('avatar-stylize:1');

        $this->avatarDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'club-avatars-'.uniqid();
        mkdir($this->avatarDir, 0777, true);
        file_put_contents($this->avatarDir.DIRECTORY_SEPARATOR.'avatar_1.png', $this->tinyPngBytes());

        config([
            'ai_assistant.deepseek.api_key' => 'sk-deepseek-test',
            'ai_assistant.deepseek.base_url' => 'https://api.deepseek.com',
            'ai_assistant.deepseek.vision_model' => 'deepseek-flash',
            'ai_assistant.avatar_dir' => $this->avatarDir,
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

    public function test_stylize_sends_photo_and_default_sample_to_deepseek(): void
    {
        $styled = $this->tinyPngBytes();

        $this->fakeHttp(function ($request) use ($styled) {
            if (str_contains($request->url(), 'images/generations')) {
                return Http::response([
                    'data' => [[
                        'b64_json' => base64_encode($styled),
                    ]],
                ]);
            }

            return Http::response('unexpected '.$request->url(), 599);
        });

        $this->actingAs($this->user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/account/profile/avatar', [
                'photo' => $this->fakeImageUpload('face.png'),
                'stylize' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->user->refresh();
        $this->assertTrue(UserAvatar::isCustom($this->user->avatar));
        Storage::disk('public')->assertExists('avatars/'.basename($this->user->avatar));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'images/generations')) {
                return false;
            }
            $images = data_get($request, 'images');
            if (! is_array($images) || count($images) < 2) {
                return false;
            }
            $first = (string) data_get($images, '0.image_url.url');
            $second = (string) data_get($images, '1.image_url.url');

            return str_starts_with($first, 'data:image/')
                && str_starts_with($second, 'data:image/')
                && $request->hasHeader('Authorization', 'Bearer sk-deepseek-test');
        });
    }

    public function test_stylize_falls_back_to_vision_chat_when_images_api_missing(): void
    {
        $styled = $this->tinyPngBytes();

        $this->fakeHttp(function ($request) use ($styled) {
            if (str_contains($request->url(), 'images/generations')) {
                return Http::response('nope', 404);
            }
            if (str_contains($request->url(), 'chat/completions')) {
                return Http::response([
                    'choices' => [[
                        'message' => [
                            'content' => [
                                [
                                    'type' => 'image_url',
                                    'image_url' => [
                                        'url' => 'data:image/png;base64,'.base64_encode($styled),
                                    ],
                                ],
                            ],
                        ],
                    ]],
                ]);
            }

            return Http::response('unexpected '.$request->url(), 599);
        });

        $this->actingAs($this->user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/account/profile/avatar', [
                'photo' => $this->fakeImageUpload('face.png'),
                'stylize' => 1,
            ])
            ->assertRedirect();

        $this->user->refresh();
        $this->assertTrue(UserAvatar::isCustom($this->user->avatar));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }
            $content = data_get($request, 'messages.1.content');
            if (! is_array($content)) {
                return false;
            }
            $urls = [];
            foreach ($content as $part) {
                $url = data_get($part, 'image_url.url');
                if (is_string($url)) {
                    $urls[] = $url;
                }
            }

            return count($urls) === 2
                && data_get($request, 'thinking.type') === 'disabled';
        });
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
}
