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
            'ai_assistant.openai.api_key' => '',
            'ai_assistant.avatar_dir' => $this->avatarDir,
            'ai_assistant.avatar.huggingface.token' => '',
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

    public function test_stylize_puts_photo_face_on_club_template(): void
    {
        Http::fake();
        file_put_contents($this->avatarDir.DIRECTORY_SEPARATOR.'avatar_1.png', $this->clubTemplatePng());

        $this->actingAs($this->user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/account/profile/avatar', [
                'photo' => UploadedFile::fake()->createWithContent('face.png', $this->redFacePng()),
                'stylize' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->user->refresh();
        $this->assertTrue(UserAvatar::isCustom($this->user->avatar));
        $stored = Storage::disk('public')->get('avatars/'.basename($this->user->avatar));
        $this->assertNotFalse($stored);
        $img = imagecreatefromstring($stored);
        $this->assertNotFalse($img);

        $face = imagecolorat($img, 256, 174);
        $this->assertGreaterThan(140, ($face >> 16) & 0xFF, 'лицо с фото должно быть в овале шаблона');
        $this->assertLessThan(120, $face & 0xFF);

        $armor = imagecolorat($img, 256, 450);
        $this->assertLessThan(90, ($armor >> 16) & 0xFF, 'низ шаблона (броня) не должен стать фото');

        $neon = imagecolorat($img, 200, 200);
        $this->assertGreaterThan(140, ($neon >> 8) & 0xFF);
        $this->assertGreaterThan((($neon >> 16) & 0xFF) + 20, ($neon >> 8) & 0xFF);

        imagedestroy($img);
        Http::assertNothingSent();
    }

    public function test_stylize_does_not_call_deepseek(): void
    {
        Http::fake();

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
        Http::assertNothingSent();
    }

    public function test_stylize_uses_huggingface_when_token_set(): void
    {
        config(['ai_assistant.avatar.huggingface.token' => 'hf_test_token']);
        $styled = $this->tinyPngBytes();

        $this->fakeHttp(function ($request) use ($styled) {
            if (str_contains($request->url(), 'huggingface')) {
                return Http::response($styled, 200, ['Content-Type' => 'image/png']);
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
        Http::assertSent(fn ($request) => str_contains($request->url(), 'huggingface')
            && $request->hasHeader('Authorization', 'Bearer hf_test_token'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'deepseek'));
    }

    public function test_stylize_uses_comfyui_when_url_set(): void
    {
        config(['ai_assistant.avatar.comfyui.url' => 'http://127.0.0.1:8188']);
        $styled = $this->tinyPngBytes();

        $this->fakeHttp(function ($request) use ($styled) {
            $url = $request->url();
            if (str_contains($url, '/upload/image')) {
                return Http::response(['name' => 'club_avatar.png', 'subfolder' => '', 'type' => 'input']);
            }
            if (str_contains($url, '/prompt')) {
                return Http::response(['prompt_id' => 'abc']);
            }
            if (str_contains($url, '/history/')) {
                return Http::response([
                    'abc' => [
                        'status' => ['completed' => true],
                        'outputs' => [
                            '9' => ['images' => [[
                                'filename' => 'out.png',
                                'subfolder' => '',
                                'type' => 'output',
                            ]]],
                        ],
                    ],
                ]);
            }
            if (str_contains($url, '/view')) {
                return Http::response($styled, 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('unexpected '.$url, 599);
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
        Http::assertSent(fn ($request) => str_contains($request->url(), '127.0.0.1:8188/upload/image'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '127.0.0.1:8188/prompt'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'deepseek'));
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

    private function clubTemplatePng(): string
    {
        $size = 512;
        $im = imagecreatetruecolor($size, $size);
        imagefilledrectangle($im, 0, 0, $size, $size, imagecolorallocate($im, 4, 6, 8));
        imagefilledrectangle($im, 0, (int) ($size * 0.68), $size, $size, imagecolorallocate($im, 36, 48, 42));
        imagefilledellipse(
            $im,
            (int) ($size * 0.50),
            (int) ($size * 0.34),
            (int) ($size * 0.42),
            (int) ($size * 0.50),
            imagecolorallocate($im, 198, 158, 128)
        );
        imagesetthickness($im, 10);
        imageline(
            $im,
            (int) ($size * 0.36),
            (int) ($size * 0.22),
            (int) ($size * 0.44),
            (int) ($size * 0.48),
            imagecolorallocate($im, 30, 230, 80)
        );
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
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
