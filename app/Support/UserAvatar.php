<?php

namespace App\Support;

class UserAvatar
{
    public const DEFAULTS = [
        'avatar_1.png',
        'avatar_2.png',
        'avatar_3.png',
        'avatar_4.png',
        'avatar_5.png',
        'avatar_6.png',
        'avatar_7.png',
        'avatar_8.png',
        'avatar_9.png',
        'avatar_10.png',
    ];

    public static function filename(?string $avatar): string
    {
        $name = trim((string) $avatar);
        if ($name === '') {
            return 'avatar_1.png';
        }
        if (! str_contains($name, '.')) {
            $name .= '.png';
        }

        return $name;
    }

    public static function isCustom(?string $avatar): bool
    {
        return str_starts_with(self::filename($avatar), 'custom/');
    }

    public static function url(?string $avatar): string
    {
        $name = self::filename($avatar);
        if (self::isCustom($name)) {
            return url('/storage/avatars/'.rawurlencode(basename($name)));
        }

        return url('/images/avatars/'.rawurlencode($name));
    }

    public static function directory(): string
    {
        $configured = trim((string) config('ai_assistant.avatar_dir', ''));

        return $configured !== '' ? $configured : public_path('images/avatars');
    }

    /**
     * Образец клубного стиля: текущий дефолтный аватар игрока, иначе первый существующий avatar_N.png.
     */
    public static function samplePath(?string $currentAvatar = null): string
    {
        $dir = self::directory();
        $current = self::filename($currentAvatar);
        if (! self::isCustom($current)) {
            $preferred = $dir.DIRECTORY_SEPARATOR.$current;
            if (is_readable($preferred)) {
                return $preferred;
            }
        }

        foreach (self::DEFAULTS as $file) {
            $path = $dir.DIRECTORY_SEPARATOR.$file;
            if (is_readable($path)) {
                return $path;
            }
        }

        throw new \RuntimeException('Нет образца клубного аватара (public/images/avatars).');
    }
}
