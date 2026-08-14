<?php

namespace App\Services\Hikvision;

use DateTimeInterface;

/**
 * ISAPI-метки (теги записи) для Hikvision NVR линейки DS-77xxNI-M4.
 *
 * Track ID = канал × 100 + 1 (камера 1 → 101, камера 12 → 1201).
 * Тег — точка на таймлайне; lock — защита интервала от перезаписи.
 */
class HikvisionIsapiMarker
{
    public const TAG_NAME_MAX = 32;

    public const DEFAULT_TAG_PATH = '/ISAPI/ContentMgmt/record/tracks/{track}/recordTag';

    public const DEFAULT_LOCK_PATH = '/ISAPI/ContentMgmt/record/tracks/{track}/lock';

    public static function trackId(?string $channel): ?int
    {
        if ($channel === null || trim($channel) === '') {
            return null;
        }

        if (! preg_match('/(\d+)/', $channel, $m)) {
            return null;
        }

        $n = (int) $m[1];
        if ($n < 1) {
            return null;
        }

        // 101, 201, 6401 — уже track ID основного потока
        if ($n >= 100) {
            return $n;
        }

        return $n * 100 + 1;
    }

    public static function tagName(string $title): string
    {
        $t = trim($title);
        if ($t === '') {
            $t = 'Reactor';
        }
        if (mb_strlen($t) > self::TAG_NAME_MAX) {
            $t = mb_substr($t, 0, self::TAG_NAME_MAX);
        }

        return $t;
    }

    public static function formatTime(DateTimeInterface $at): string
    {
        return $at->format('Y-m-d\TH:i:sP');
    }

    public static function recordTagXml(string $name, DateTimeInterface $at): string
    {
        $nameEsc = htmlspecialchars(self::tagName($name), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $time = self::formatTime($at);

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<RecordTag version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema">'
            .'<id>0</id>'
            .'<name>'.$nameEsc.'</name>'
            .'<time>'.$time.'</time>'
            .'</RecordTag>';
    }

    public static function lockXml(DateTimeInterface $start, DateTimeInterface $end): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<LockRegion version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema">'
            .'<startTime>'.self::formatTime($start).'</startTime>'
            .'<endTime>'.self::formatTime($end).'</endTime>'
            .'</LockRegion>';
    }

    public static function tagPath(int $trackId, ?string $webhookPath = null): string
    {
        $custom = trim((string) $webhookPath);
        if ($custom !== '' && ! str_contains($custom, 'markers')) {
            return self::expandPath($custom, $trackId);
        }

        return self::expandPath(self::DEFAULT_TAG_PATH, $trackId);
    }

    public static function lockPath(int $trackId): string
    {
        return self::expandPath(self::DEFAULT_LOCK_PATH, $trackId);
    }

    public static function absoluteUrl(string $base, string $path): string
    {
        $base = rtrim($base, '/');
        $path = '/'.ltrim($path, '/');

        return $base.$path;
    }

    private static function expandPath(string $path, int $trackId): string
    {
        $path = '/'.ltrim($path, '/');
        if (str_contains($path, '{track}')) {
            return str_replace('{track}', (string) $trackId, $path);
        }

        return $path;
    }
}
