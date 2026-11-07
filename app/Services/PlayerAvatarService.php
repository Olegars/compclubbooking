<?php

namespace App\Services;

use App\Models\AiAssistantSetting;
use App\Models\User;
use App\Services\Avatar\GlmImageEditClient;
use App\Services\Avatar\QwenImageEditClient;
use App\Support\UserAvatar;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PlayerAvatarService
{
    private const NEGATIVE = 'photo pasted on illustration, collage, oval cutout, sticker, extra face, extra person, watermark, blurry, deformed, text';

    public function __construct(
        private readonly QwenImageEditClient $qwen,
        private readonly GlmImageEditClient $glm,
    ) {}

    public function save(User $user, UploadedFile $photo, bool $stylize = false, ?string $sampleName = null): void
    {
        $bytes = $this->readBytes($photo);
        $mime = $this->detectMime($bytes, $photo);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new RuntimeException('Нужен JPEG, PNG, WebP или GIF.');
        }

        if ($stylize) {
            $provider = AiAssistantSetting::forClub(null)->resolvedAvatarProvider();
            $client = $provider === 'glm' ? $this->glm : $this->qwen;
            if (! $client->configured()) {
                throw new RuntimeException($provider === 'glm'
                    ? 'Стилизация недоступна: не задан ключ GLM.'
                    : 'Стилизация недоступна: не задан ключ Qwen.');
            }
            if (function_exists('set_time_limit')) {
                @set_time_limit(160);
            }
            $this->assertStylizeAllowed($user);
            $photoPacked = $this->compress($bytes, $mime, 1024);
            $samplePacked = $this->samplePacked((string) $sampleName);
            $styled = $client->stylize(
                $photoPacked['bytes'],
                $samplePacked['bytes'],
                $this->clubAvatarPrompt(),
                self::NEGATIVE,
            );
            if ($styled === null) {
                throw new RuntimeException('Не удалось стилизовать аватар. Попробуйте ещё раз.');
            }
            $bytes = $styled;
            $mime = 'image/png';
        }

        $png = $this->toSquarePng($bytes, $mime);
        $this->store($user, $png);
    }

    private function assertStylizeAllowed(User $user): void
    {
        $key = 'avatar-stylize:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 4)) {
            throw new RuntimeException('Слишком часто. Подождите несколько минут.');
        }
        RateLimiter::hit($key, 600);
    }

    private function clubAvatarPrompt(): string
    {
        return 'Image 1 is a photo of one person. Image 2 is a club avatar used only as a style reference. Draw a new square avatar of the person from image 1 in the illustration style of image 2: similar armor, neon, colors and linework. Keep the face recognizably similar. One coherent character. Do not paste the photo onto the template, do not cut an oval, do not make a collage, no extra person.';
    }

    private function store(User $user, string $png): void
    {
        $name = 'u'.$user->id.'_'.Str::lower(Str::random(12)).'.png';
        $path = 'avatars/'.$name;
        if (! Storage::disk('public')->put($path, $png)) {
            throw new RuntimeException('Не удалось сохранить фото.');
        }

        $previous = (string) $user->getAttribute('avatar');
        $user->forceFill(['avatar' => 'custom/'.$name])->save();
        $this->deleteCustomFile($previous);
    }

    private function deleteCustomFile(?string $avatar): void
    {
        if (! UserAvatar::isCustom($avatar)) {
            return;
        }
        $path = 'avatars/'.basename(UserAvatar::filename($avatar));
        Storage::disk('public')->delete($path);
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    private function readFile(string $path): array
    {
        $bytes = (string) file_get_contents($path);
        if ($bytes === '') {
            throw new RuntimeException('Не удалось прочитать образец аватара.');
        }

        return [
            'bytes' => $bytes,
            'mime' => $this->detectMime($bytes, null),
        ];
    }

    private function readBytes(UploadedFile $photo): string
    {
        try {
            $fromUpload = (string) $photo->getContent();
            if ($fromUpload !== '') {
                return $fromUpload;
            }
        } catch (\Throwable) {
        }

        $path = $photo->getRealPath() ?: $photo->getPathname();
        if (is_string($path) && $path !== '' && is_readable($path)) {
            $fromDisk = file_get_contents($path);
            if ($fromDisk !== false && $fromDisk !== '') {
                return $fromDisk;
            }
        }

        throw new RuntimeException('Не удалось прочитать фото.');
    }

    private function detectMime(string $bytes, ?UploadedFile $photo): string
    {
        if (str_starts_with($bytes, "\xff\xd8\xff")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, 'GIF8')) {
            return 'image/gif';
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        if ($photo) {
            $client = strtolower((string) ($photo->getMimeType() ?: ''));
            if ($client === 'image/jpg') {
                $client = 'image/jpeg';
            }
            if (str_starts_with($client, 'image/')) {
                return $client;
            }
        }

        return 'application/octet-stream';
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    private function samplePacked(string $sampleName): array
    {
        $sampleName = basename($sampleName);
        if (! in_array($sampleName, UserAvatar::DEFAULTS, true)) {
            throw new RuntimeException('Выберите клубный аватар.');
        }

        $path = UserAvatar::directory().DIRECTORY_SEPARATOR.$sampleName;
        if (! is_readable($path)) {
            throw new RuntimeException('Нет образца клубного аватара.');
        }

        $sample = $this->readFile($path);

        return $this->compress($sample['bytes'], $sample['mime'], 1024);
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    private function compress(string $bytes, string $mime, int $maxSide = 1024): array
    {
        if (! function_exists('imagecreatefromstring')) {
            return ['bytes' => $bytes, 'mime' => $mime];
        }

        $src = $this->gdFromBytes($bytes);
        if ($src === null) {
            return ['bytes' => $bytes, 'mime' => $mime];
        }

        $width = imagesx($src);
        $height = imagesy($src);
        $maxSide = max(64, $maxSide);
        $scale = max($width, $height) > $maxSide
            ? $maxSide / max($width, $height)
            : 1.0;

        if ($scale < 1) {
            $dstW = max(1, (int) round($width * $scale));
            $dstH = max(1, (int) round($height * $scale));
            $dst = imagecreatetruecolor($dstW, $dstH);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $width, $height);
            imagedestroy($src);
            $src = $dst;
        }

        ob_start();
        imagepng($src, null, 8);
        $out = (string) ob_get_clean();
        imagedestroy($src);

        return $out !== ''
            ? ['bytes' => $out, 'mime' => 'image/png']
            : ['bytes' => $bytes, 'mime' => $mime];
    }

    private function toSquarePng(string $bytes, string $mime): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $bytes;
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return $bytes;
        }

        $width = imagesx($src);
        $height = imagesy($src);
        $side = min($width, $height);
        $sx = (int) floor(($width - $side) / 2);
        $sy = (int) floor(($height - $side) / 2);
        $size = 512;
        $dst = imagecreatetruecolor($size, $size);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $size, $size, $transparent);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);
        imagedestroy($src);

        ob_start();
        imagepng($dst, null, 8);
        $out = (string) ob_get_clean();
        imagedestroy($dst);

        return $out !== '' ? $out : $bytes;
    }

    /**
     * @return \GdImage|null
     */
    private function gdFromBytes(string $bytes)
    {
        if ($bytes === '' || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src !== false) {
            return $src;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'clubimg');
        if ($tmp === false) {
            return null;
        }
        file_put_contents($tmp, $bytes);
        foreach (['imagecreatefrompng', 'imagecreatefromjpeg', 'imagecreatefromwebp', 'imagecreatefromgif'] as $fn) {
            if (! function_exists($fn)) {
                continue;
            }
            $src = @$fn($tmp);
            if ($src !== false) {
                @unlink($tmp);

                return $src;
            }
        }
        @unlink($tmp);

        return null;
    }
}
