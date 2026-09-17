<?php

namespace App\Services;

use App\Models\User;
use App\Services\AiAssistant\DeepSeekChat;
use App\Services\Avatar\ComfyUiAvatarClient;
use App\Services\Avatar\HuggingFaceAvatarClient;
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
        private readonly HuggingFaceAvatarClient $huggingface,
        private readonly ComfyUiAvatarClient $comfyui,
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
            $bytes = $this->stylizePhoto($photoPacked['bytes'], $samplePacked['bytes'])
                ?: $photoPacked['bytes'];
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

    private function stylizePhoto(string $photoBytes, string $sampleBytes): ?string
    {
        $prompt = $this->clubAvatarPrompt();
        $negative = 'photo, selfie, blurry, extra face, watermark, low quality, deformed';
        $blend = null;
        try {
            $blend = $this->blendClubFormat($photoBytes, $sampleBytes);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            if (trim((string) config('ai_assistant.avatar.comfyui.url', '')) !== '') {
                $loads = $this->comfyui->loadImageCount();
                $comfyImages = $loads >= 2
                    ? [
                        ['bytes' => $photoBytes, 'name' => 'club_face.png'],
                        ['bytes' => $sampleBytes, 'name' => 'club_style.png'],
                    ]
                    : [['bytes' => $blend ?: $photoBytes, 'name' => 'club_avatar.png']];
                $fromComfy = $this->comfyui->generate($comfyImages, $prompt, $negative);
                if ($fromComfy !== null) {
                    return $fromComfy;
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $canvas = $blend ?: $photoBytes;
        try {
            $fromHf = $this->huggingface->refine($canvas, $prompt, $negative);
            if ($fromHf !== null) {
                return $fromHf;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $fromOpenAi = $this->llm->stylizeWithOpenAi($photoBytes, $sampleBytes);
            if ($fromOpenAi !== null) {
                return $fromOpenAi;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $blend;
    }

    private function clubAvatarPrompt(): string
    {
        return 'Cyberpunk digital illustration club avatar, keep the same face and composition, neon green circuit tattoos, armored collar, dark background, sharp ink lines, no extra person';
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
     * Лицо с фото — в овал стандартного клубного аватара. Броня, волосы, неон шаблона остаются.
     */
    private function blendClubFormat(string $photoBytes, string $sampleBytes): ?string
    {
        $sample = $this->gdFromBytes($sampleBytes);
        $photo = $this->gdFromBytes($photoBytes);
        if ($sample === null || $photo === null) {
            if ($sample !== null) {
                imagedestroy($sample);
            }
            if ($photo !== null) {
                imagedestroy($photo);
            }

            return null;
        }

        $size = 512;
        $base = $this->squareTruecolor($sample, $size);
        $face = $this->squareTruecolor($photo, $size);
        imagedestroy($sample);
        imagedestroy($photo);
        if ($base === null || $face === null) {
            if ($base !== null) {
                imagedestroy($base);
            }
            if ($face !== null) {
                imagedestroy($face);
            }

            return null;
        }

        $stamp = imagecreatetruecolor($size, $size);
        imagecopy($stamp, $base, 0, 0, 0, 0, $size, $size);

        $oval = $this->detectTemplateFaceOval($base, $size);
        $src = $this->detectPhotoFaceRect($face);
        $match = $this->skinMatch($base, $face, $oval, $src);
        $cx = $oval['cx'];
        $cy = $oval['cy'];
        $rx = max(8.0, $oval['rx']);
        $ry = max(8.0, $oval['ry']);
        $minX = max(0, (int) floor($cx - $rx) - 1);
        $maxX = min($size - 1, (int) ceil($cx + $rx) + 1);
        $minY = max(0, (int) floor($cy - $ry) - 1);
        $maxY = min($size - 1, (int) ceil($cy + $ry) + 1);

        for ($y = $minY; $y <= $maxY; $y++) {
            for ($x = $minX; $x <= $maxX; $x++) {
                $dx = ($x - $cx) / $rx;
                $dy = ($y - $cy) / $ry;
                $d2 = $dx * $dx + $dy * $dy;
                if ($d2 > 1) {
                    continue;
                }
                if ($this->isNeonPixel(imagecolorat($stamp, $x, $y))) {
                    continue;
                }
                $a = $d2 < 0.70 ? 1.0 : (1.0 - $d2) / 0.30;
                $a = max(0.0, min(1.0, $a));
                $u = ($dx + 1) / 2;
                $v = ($dy + 1) / 2;
                $fx = $src['x'] + $u * max(1.0, $src['w'] - 1);
                $fy = $src['y'] + $v * max(1.0, $src['h'] - 1);
                $prgb = $this->sampleBilinear($face, $fx, $fy);
                $brgb = imagecolorat($base, $x, $y);
                $r = $this->matchChannel(($prgb >> 16) & 0xFF, $match[0]);
                $g = $this->matchChannel(($prgb >> 8) & 0xFF, $match[1]);
                $b = $this->matchChannel($prgb & 0xFF, $match[2]);
                $r = $this->mixChannel($r, ($brgb >> 16) & 0xFF, $a);
                $g = $this->mixChannel($g, ($brgb >> 8) & 0xFF, $a);
                $b = $this->mixChannel($b, $brgb & 0xFF, $a);
                imagesetpixel($base, $x, $y, imagecolorallocate($base, $r, $g, $b));
            }
        }

        for ($y = $minY; $y <= $maxY; $y++) {
            for ($x = $minX; $x <= $maxX; $x++) {
                $srgb = imagecolorat($stamp, $x, $y);
                if ($this->isNeonPixel($srgb)) {
                    imagesetpixel($base, $x, $y, $srgb);
                }
            }
        }

        imagedestroy($face);
        imagedestroy($stamp);

        ob_start();
        imagepng($base, null, 8);
        $out = (string) ob_get_clean();
        imagedestroy($base);

        return $out !== '' ? $out : null;
    }

    /**
     * @param  \GdImage  $img
     * @return array{cx: float, cy: float, rx: float, ry: float}
     */
    private function detectTemplateFaceOval($img, int $size): array
    {
        $fallback = [
            'cx' => $size * 0.50,
            'cy' => $size * 0.34,
            'rx' => $size * 0.20,
            'ry' => $size * 0.24,
        ];
        $sx = $sy = $n = 0;
        $minX = $size;
        $minY = $size;
        $maxX = 0;
        $maxY = 0;
        $y0 = (int) ($size * 0.06);
        $y1 = (int) ($size * 0.62);
        $x0 = (int) ($size * 0.16);
        $x1 = (int) ($size * 0.84);
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                if (! $this->isTemplateSkin(imagecolorat($img, $x, $y))) {
                    continue;
                }
                $sx += $x;
                $sy += $y;
                $n++;
                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
            }
        }
        if ($n < 120) {
            return $fallback;
        }
        $cx = $sx / $n;
        $cy = $sy / $n;
        $rx = max($cx - $minX, $maxX - $cx) * 0.92;
        $ry = max($cy - $minY, $maxY - $cy) * 0.90;

        return [
            'cx' => $cx,
            'cy' => $cy,
            'rx' => max($size * 0.12, min($size * 0.28, $rx)),
            'ry' => max($size * 0.14, min($size * 0.32, $ry)),
        ];
    }

    /**
     * @param  \GdImage  $img
     * @return array{x: float, y: float, w: float, h: float}
     */
    private function detectPhotoFaceRect($img): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $sx = $sy = $n = 0;
        $minX = $w;
        $minY = $h;
        $maxX = 0;
        $maxY = 0;
        $step = max(1, (int) floor(min($w, $h) / 96));
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if (! $this->isPhotoSkin(imagecolorat($img, $x, $y))) {
                    continue;
                }
                $sx += $x;
                $sy += $y;
                $n++;
                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
            }
        }
        if ($n < 40) {
            $side = min($w, $h) * 0.62;
            $x = ($w - $side) / 2;
            $y = ($h - $side) * 0.38;

            return ['x' => $x, 'y' => max(0, $y), 'w' => $side, 'h' => $side];
        }
        $padX = ($maxX - $minX) * 0.08;
        $padY = ($maxY - $minY) * 0.10;
        $x = max(0, $minX - $padX);
        $y = max(0, $minY - $padY);
        $rw = min($w - $x, ($maxX - $minX) + $padX * 2);
        $rh = min($h - $y, ($maxY - $minY) + $padY * 2);

        return ['x' => $x, 'y' => $y, 'w' => max(8, $rw), 'h' => max(8, $rh)];
    }

    /**
     * @param  \GdImage  $template
     * @param  \GdImage  $photo
     * @param  array{cx: float, cy: float, rx: float, ry: float}  $oval
     * @param  array{x: float, y: float, w: float, h: float}  $src
     * @return array{0: float, 1: float, 2: float}
     */
    private function skinMatch($template, $photo, array $oval, array $src): array
    {
        $tr = $tg = $tb = $tn = 0;
        $pr = $pg = $pb = $pn = 0;
        $size = imagesx($template);
        $step = 3;
        for ($y = 0; $y < $size; $y += $step) {
            for ($x = 0; $x < $size; $x += $step) {
                $dx = ($x - $oval['cx']) / max(1.0, $oval['rx']);
                $dy = ($y - $oval['cy']) / max(1.0, $oval['ry']);
                if (($dx * $dx + $dy * $dy) > 0.55) {
                    continue;
                }
                $rgb = imagecolorat($template, $x, $y);
                if ($this->isNeonPixel($rgb) || ! $this->isTemplateSkin($rgb)) {
                    continue;
                }
                $tr += ($rgb >> 16) & 0xFF;
                $tg += ($rgb >> 8) & 0xFF;
                $tb += $rgb & 0xFF;
                $tn++;
            }
        }
        $pw = imagesx($photo);
        $ph = imagesy($photo);
        for ($i = 0; $i < 80; $i++) {
            $px = (int) min($pw - 1, max(0, round($src['x'] + ($src['w'] * ($i % 10) / 9))));
            $py = (int) min($ph - 1, max(0, round($src['y'] + ($src['h'] * intdiv($i, 10) / 7))));
            $rgb = imagecolorat($photo, $px, $py);
            $pr += ($rgb >> 16) & 0xFF;
            $pg += ($rgb >> 8) & 0xFF;
            $pb += $rgb & 0xFF;
            $pn++;
        }
        if ($tn < 8 || $pn < 8) {
            return [1.0, 1.0, 1.0];
        }
        $clamp = static fn (float $v): float => max(0.82, min(1.18, $v));

        return [
            $clamp(($tr / $tn) / max(1.0, $pr / $pn)),
            $clamp(($tg / $tn) / max(1.0, $pg / $pn)),
            $clamp(($tb / $tn) / max(1.0, $pb / $pn)),
        ];
    }

    /**
     * @param  \GdImage  $img
     */
    private function sampleBilinear($img, float $x, float $y): int
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $x = max(0.0, min($w - 1.001, $x));
        $y = max(0.0, min($h - 1.001, $y));
        $x0 = (int) floor($x);
        $y0 = (int) floor($y);
        $x1 = min($w - 1, $x0 + 1);
        $y1 = min($h - 1, $y0 + 1);
        $fx = $x - $x0;
        $fy = $y - $y0;
        $c00 = imagecolorat($img, $x0, $y0);
        $c10 = imagecolorat($img, $x1, $y0);
        $c01 = imagecolorat($img, $x0, $y1);
        $c11 = imagecolorat($img, $x1, $y1);
        $mix = function (int $a, int $b, float $t): int {
            $shift = static fn (int $c, int $s): int => ($c >> $s) & 0xFF;
            $r = (int) round($shift($a, 16) * (1 - $t) + $shift($b, 16) * $t);
            $g = (int) round($shift($a, 8) * (1 - $t) + $shift($b, 8) * $t);
            $bl = (int) round($shift($a, 0) * (1 - $t) + $shift($b, 0) * $t);

            return ($r << 16) | ($g << 8) | $bl;
        };
        $top = $mix($c00, $c10, $fx);
        $bot = $mix($c01, $c11, $fx);

        return $mix($top, $bot, $fy);
    }

    private function isNeonPixel(int $rgb): bool
    {
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return $g > 145 && $g > $r + 28 && $g > $b + 22;
    }

    private function isTemplateSkin(int $rgb): bool
    {
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        if ($max < 70 || $max > 245 || ($max - $min) < 12) {
            return false;
        }
        if ($g > $r + 16 && $g > $b + 10) {
            return false;
        }
        if ($b > $r + 22 && $b > $g + 12) {
            return false;
        }

        return $r >= $g - 10 && $r >= $b - 8;
    }

    private function isPhotoSkin(int $rgb): bool
    {
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);

        return $r > 40 && $g > 18 && $b > 12
            && $r >= $g && $r >= $b
            && ($r - $g) >= 8
            && ($max - $min) >= 12
            && $max > 50;
    }

    private function matchChannel(int $src, float $scale): int
    {
        $lumaBoost = ($src - 128) * 1.06 + 128;

        return (int) max(0, min(255, round($lumaBoost * $scale)));
    }

    private function mixChannel(int $src, int $dst, float $a): int
    {
        return (int) round($src * $a + $dst * (1 - $a));
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
}
