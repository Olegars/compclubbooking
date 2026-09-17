<?php

namespace App\Services;

use App\Models\User;
use App\Services\AiAssistant\DeepSeekChat;
use App\Support\UserAvatar;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PlayerAvatarService
{
    public function __construct(
        private readonly DeepSeekChat $llm,
    ) {}

    public function save(User $user, UploadedFile $photo, bool $stylize = false): void
    {
        $bytes = $this->readBytes($photo);
        $mime = $this->detectMime($bytes, $photo);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new RuntimeException('Нужен JPEG, PNG, WebP или GIF.');
        }

        if ($stylize) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(130);
            }
            $this->assertStylizeAllowed($user);
            $photoPacked = $this->compress($bytes, $mime);
            $photoUrl = $this->dataUrl($photoPacked);
            $sample = $this->readFile(UserAvatar::samplePath($user->avatar));
            $samplePacked = $this->compress($sample['bytes'], $sample['mime']);
            $sampleUrl = $this->dataUrl($samplePacked);
            $styled = $this->llm->stylizeAvatar($photoUrl, $sampleUrl)
                ?? $this->llm->stylizeWithOpenAi($photoPacked['bytes'], $samplePacked['bytes'])
                ?? $this->blendClubFormat($photoPacked['bytes'], $samplePacked['bytes']);
            if ($styled === null) {
                throw new RuntimeException('Не удалось стилизовать фото.');
            }
            $bytes = $styled;
            $mime = $this->detectMime($bytes, $photo);
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
    private function compress(string $bytes, string $mime): array
    {
        if (! function_exists('imagecreatefromstring')) {
            return ['bytes' => $bytes, 'mime' => $mime];
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return ['bytes' => $bytes, 'mime' => $mime];
        }

        $width = imagesx($src);
        $height = imagesy($src);
        $maxSide = 1024;
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
     * Хостовый DeepSeek не рисует PNG — смешиваем фото с клубным образцом.
     */
    private function blendClubFormat(string $photoBytes, string $sampleBytes): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $photo = @imagecreatefromstring($photoBytes);
        $sample = @imagecreatefromstring($sampleBytes);
        if ($photo === false || $sample === false) {
            if (is_object($photo)) {
                imagedestroy($photo);
            }
            if (is_object($sample)) {
                imagedestroy($sample);
            }

            return null;
        }

        $size = 512;
        $face = $this->squareTruecolor($photo, $size);
        $style = $this->squareTruecolor($sample, $size);
        imagedestroy($photo);
        imagedestroy($sample);
        if ($face === null || $style === null) {
            if ($face) {
                imagedestroy($face);
            }
            if ($style) {
                imagedestroy($style);
            }

            return null;
        }

        imagecopymerge($face, $style, 0, 0, 0, 0, $size, $size, 42);
        imagefilter($face, IMG_FILTER_CONTRAST, -18);
        imagefilter($face, IMG_FILTER_COLORIZE, 8, 48, 12, 0);
        imagefilter($face, IMG_FILTER_BRIGHTNESS, -8);
        imagedestroy($style);

        ob_start();
        imagepng($face, null, 8);
        $out = (string) ob_get_clean();
        imagedestroy($face);

        return $out !== '' ? $out : null;
    }

    /**
     * @param  \GdImage  $src
     * @return \GdImage|null
     */
    private function squareTruecolor($src, int $size)
    {
        $width = imagesx($src);
        $height = imagesy($src);
        if ($width < 1 || $height < 1) {
            return null;
        }
        $side = min($width, $height);
        $sx = (int) floor(($width - $side) / 2);
        $sy = (int) floor(($height - $side) / 2);
        $dst = imagecreatetruecolor($size, $size);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);

        return $dst;
    }

    /**
     * @param  array{bytes: string, mime: string}|string  $packed
     */
    private function dataUrl(array|string $packed): string
    {
        if (is_string($packed)) {
            $packed = ['bytes' => $packed, 'mime' => 'image/png'];
        }
        $mime = $packed['mime'] !== '' ? $packed['mime'] : 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($packed['bytes']);
    }
}
