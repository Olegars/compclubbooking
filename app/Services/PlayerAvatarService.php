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
            $photoPacked = $this->compress($bytes, $mime, 512);
            $samplePacked = $this->samplePacked($user);
            $styled = null;
            try {
                $styled = $this->llm->stylizeAvatar(
                    $this->dataUrl($photoPacked),
                    $this->dataUrl($samplePacked),
                );
            } catch (\Throwable $e) {
                report($e);
            }
            if ($styled === null) {
                try {
                    $styled = $this->llm->stylizeWithOpenAi($photoPacked['bytes'], $samplePacked['bytes']);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
            if ($styled === null) {
                try {
                    $styled = $this->blendClubFormat($photoPacked['bytes'], $samplePacked['bytes']);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
            $bytes = $styled ?: $photoPacked['bytes'];
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
    private function samplePacked(User $user): array
    {
        try {
            $sample = $this->readFile(UserAvatar::samplePath($user->avatar));
        } catch (\Throwable $e) {
            report($e);

            return $this->compress($this->tinyClubPng(), 'image/png', 512);
        }

        return $this->compress($sample['bytes'], $sample['mime'], 512);
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
     * DeepSeek API не отдаёт PNG: стилизуем само фото под клуб
     * (неон, схемы, тёмный круг). Образец — только цвет, не чужое лицо.
     */
    private function blendClubFormat(string $photoBytes, string $sampleBytes): ?string
    {
        $photo = $this->gdFromBytes($photoBytes);
        if ($photo === null) {
            return $photoBytes !== '' ? $photoBytes : null;
        }

        $size = 512;
        $face = $this->squareTruecolor($photo, $size);
        imagedestroy($photo);
        if ($face === null) {
            return $photoBytes !== '' ? $photoBytes : null;
        }

        $neon = [34, 230, 90];
        $sample = $this->gdFromBytes($sampleBytes);
        if ($sample !== null) {
            $style = $this->squareTruecolor($sample, $size);
            imagedestroy($sample);
            if ($style !== null) {
                $neon = $this->sampleNeon($style);
                imagedestroy($style);
            }
        }

        $this->gradeClubFace($face, $neon);
        $this->drawClubCircuits($face, $neon);
        $this->circleOnBlack($face, $neon);

        ob_start();
        imagepng($face, null, 8);
        $out = (string) ob_get_clean();
        imagedestroy($face);

        return $out !== '' ? $out : $photoBytes;
    }

    /**
     * @param  \GdImage  $img
     * @param  array{0:int,1:int,2:int}  $neon
     */
    private function gradeClubFace($img, array $neon): void
    {
        if (function_exists('imagefilter')) {
            imagefilter($img, IMG_FILTER_CONTRAST, -28);
            imagefilter($img, IMG_FILTER_BRIGHTNESS, -12);
            imagefilter($img, IMG_FILTER_COLORIZE, -20, 28, -18, 0);
            $edges = imagecreatetruecolor(imagesx($img), imagesy($img));
            if ($edges !== false) {
                imagecopy($edges, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
                imagefilter($edges, IMG_FILTER_EDGEDETECT);
                imagefilter($edges, IMG_FILTER_COLORIZE, -90, 70, -90, 0);
                imagecopymerge($img, $edges, 0, 0, 0, 0, imagesx($img), imagesy($img), 22);
                imagedestroy($edges);
            }
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $cx = ($w - 1) / 2;
        $cy = ($h - 1) / 2;
        $maxR = hypot($cx, $cy);
        $nr = $neon[0] / 255;
        $ng = $neon[1] / 255;
        $nb = $neon[2] / 255;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $dist = hypot($x - $cx, $y - $cy) / $maxR;
                $vignette = 1 - ($dist * $dist * 0.92);
                $r = (int) max(0, min(255, $r * $vignette * 0.82));
                $g = (int) max(0, min(255, $g * $vignette * 0.92 + $ng * 28));
                $b = (int) max(0, min(255, $b * $vignette * 0.72));
                $r = (int) max(0, min(255, $r + $nr * 10));
                $b = (int) max(0, min(255, $b + $nb * 8));
                imagesetpixel($img, $x, $y, imagecolorallocate($img, $r, $g, $b));
            }
        }
    }

    /**
     * @param  \GdImage  $img
     * @param  array{0:int,1:int,2:int}  $neon
     */
    private function drawClubCircuits($img, array $neon): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $color = imagecolorallocate($img, $neon[0], $neon[1], $neon[2]);
        $dim = imagecolorallocate($img, (int) ($neon[0] * 0.45), (int) ($neon[1] * 0.55), (int) ($neon[2] * 0.4));
        imagesetthickness($img, max(2, (int) round($w / 160)));

        $line = function (int $x1, int $y1, int $x2, int $y2) use ($img, $color, $w, $h): void {
            imageline($img, (int) round($x1 * $w / 512), (int) round($y1 * $h / 512), (int) round($x2 * $w / 512), (int) round($y2 * $h / 512), $color);
        };

        $line(170, 70, 210, 118);
        $line(210, 118, 248, 90);
        $line(248, 90, 268, 128);
        $line(340, 72, 300, 120);
        $line(300, 120, 328, 158);
        $line(120, 210, 168, 198);
        $line(168, 198, 188, 248);
        $line(188, 248, 150, 300);
        $line(392, 210, 344, 198);
        $line(344, 198, 324, 250);
        $line(324, 250, 362, 305);
        $line(200, 330, 256, 312);
        $line(256, 312, 312, 330);

        imagesetthickness($img, 1);
        imageellipse($img, (int) round(188 * $w / 512), (int) round(198 * $h / 512), max(6, (int) round($w / 42)), max(6, (int) round($h / 42)), $dim);
        imageellipse($img, (int) round(324 * $w / 512), (int) round(198 * $h / 512), max(6, (int) round($w / 42)), max(6, (int) round($h / 42)), $dim);
    }

    /**
     * @param  \GdImage  $img
     * @param  array{0:int,1:int,2:int}  $neon
     */
    private function circleOnBlack($img, array $neon): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $cx = ($w - 1) / 2.0;
        $cy = ($h - 1) / 2.0;
        $radius = min($w, $h) / 2 - 4;
        $black = imagecolorallocate($img, 0, 0, 0);
        $ring = imagecolorallocate($img, $neon[0], $neon[1], $neon[2]);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (hypot($x - $cx, $y - $cy) > $radius) {
                    imagesetpixel($img, $x, $y, $black);
                }
            }
        }

        imagesetthickness($img, max(3, (int) round($w / 85)));
        imageellipse($img, (int) $cx, (int) $cy, (int) round($radius * 2), (int) round($radius * 2), $ring);
    }

    /**
     * @param  \GdImage  $img
     * @return array{0:int,1:int,2:int}
     */
    private function sampleNeon($img): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $step = max(1, (int) floor(min($w, $h) / 48));
        $sr = $sg = $sb = $n = 0;
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                if ($g > 140 && $g > $r + 25 && $g > $b + 25) {
                    $sr += $r;
                    $sg += $g;
                    $sb += $b;
                    $n++;
                }
            }
        }

        if ($n < 4) {
            return [34, 230, 90];
        }

        return [
            (int) round($sr / $n),
            (int) round($sg / $n),
            (int) round($sb / $n),
        ];
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

    private function tinyClubPng(): string
    {
        $decoded = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );

        return is_string($decoded) && $decoded !== '' ? $decoded : $this->minimalPng();
    }

    private function minimalPng(): string
    {
        return "\x89PNG\r\n\x1a\n";
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
