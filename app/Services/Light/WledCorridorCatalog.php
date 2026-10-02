<?php

namespace App\Services\Light;

/**
 * Corridor GLEDOPTO / WLED alerts. Shell paints HTTP JSON; cloud only queues cues.
 * Four outputs are WLED segments 0–3 (one segment per channel).
 */
class WledCorridorCatalog
{
    public const CHANNELS = 4;

    public const EFFECT_SOLID = 'solid';

    public const EFFECT_BLINK = 'blink';

    /**
     * @return list<array{id:string,title:string,hint:string,priority:int}>
     */
    public static function definitions(): array
    {
        return [
            [
                'id' => 'bar.order',
                'title' => 'Поступление заказа',
                'hint' => 'Заказ бара стал в работу (сразу или когда подошла отложенная доставка). Админ видит вспышку в коридоре.',
                'priority' => 60,
            ],
            [
                'id' => 'sos',
                'title' => 'SOS с места',
                'hint' => 'Гость вызвал администратора с ПК или TV. Вспышка на всех привязанных коридорных контроллерах.',
                'priority' => 90,
            ],
            [
                'id' => 'booking.new',
                'title' => 'Новая бронь',
                'hint' => 'Появилась бронь места. Несколько ПК в одной брони дают одну вспышку, не по вспышке на кресло.',
                'priority' => 40,
            ],
        ];
    }

    public static function has(string $eventId): bool
    {
        foreach (self::definitions() as $def) {
            if ($def['id'] === $eventId) {
                return true;
            }
        }

        return false;
    }

    public static function priority(string $eventId): int
    {
        foreach (self::definitions() as $def) {
            if ($def['id'] === $eventId) {
                return (int) $def['priority'];
            }
        }

        return 50;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function defaultMap(): array
    {
        $map = [];
        foreach (self::definitions() as $def) {
            $enabled = $def['id'] !== 'booking.new';
            $color = match ($def['id']) {
                'sos' => 'red',
                'booking.new' => 'blue',
                default => 'orange',
            };
            $map[$def['id']] = [
                'enabled' => $enabled,
                'color' => $color,
                'brightness' => 100,
                'effect' => $def['id'] === 'booking.new' ? self::EFFECT_SOLID : self::EFFECT_BLINK,
                'duration_sec' => match ($def['id']) {
                    'sos' => 12,
                    'booking.new' => 5,
                    default => 8,
                },
                'fade_sec' => 0.2,
                'channels' => [1, 2, 3, 4],
            ];
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, array<string, mixed>>
     */
    public static function sanitizeMap(array $incoming): array
    {
        $defaults = self::defaultMap();
        $out = [];
        foreach ($defaults as $id => $base) {
            $raw = is_array($incoming[$id] ?? null) ? $incoming[$id] : [];
            $out[$id] = self::sanitizeBinding(array_merge($base, $raw), $base);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public static function sanitizeBinding(array $raw, array $defaults): array
    {
        $channels = [];
        foreach ((array) ($raw['channels'] ?? $defaults['channels'] ?? []) as $ch) {
            $n = (int) $ch;
            if ($n >= 1 && $n <= self::CHANNELS) {
                $channels[$n] = $n;
            }
        }
        if ($channels === []) {
            $channels = [1 => 1, 2 => 2, 3 => 3, 4 => 4];
        }

        $color = (string) ($raw['color'] ?? $defaults['color'] ?? 'orange');
        if (! in_array($color, LightEventCatalog::EVENT_COLORS, true)) {
            $color = (string) ($defaults['color'] ?? 'orange');
        }

        $effect = (string) ($raw['effect'] ?? $defaults['effect'] ?? self::EFFECT_BLINK);

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'color' => $color,
            'brightness' => max(1, min(100, (int) ($raw['brightness'] ?? $defaults['brightness'] ?? 100))),
            'effect' => $effect === self::EFFECT_SOLID ? self::EFFECT_SOLID : self::EFFECT_BLINK,
            'duration_sec' => max(0, min(120, (float) ($raw['duration_sec'] ?? $defaults['duration_sec'] ?? 8))),
            'fade_sec' => max(0, min(10, (float) ($raw['fade_sec'] ?? $defaults['fade_sec'] ?? 0.2))),
            'channels' => array_values($channels),
        ];
    }

    /**
     * Rows for the admin form (catalog order, stored flags over defaults).
     *
     * @param  array<string, mixed>|null  $stored
     * @return list<array{id:string,title:string,hint:string,settings:array<string,mixed>}>
     */
    public static function forAdmin(?array $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $map = self::sanitizeMap($stored);
        $rows = [];
        foreach (self::definitions() as $def) {
            $rows[] = [
                'id' => $def['id'],
                'title' => $def['title'],
                'hint' => $def['hint'],
                'settings' => $map[$def['id']],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $binding
     * @return array<string, mixed>
     */
    public static function playState(array $binding): array
    {
        $channels = array_map('intval', (array) ($binding['channels'] ?? [1, 2, 3, 4]));
        $rgb = self::rgb((string) ($binding['color'] ?? 'orange'));
        $bri = (int) round(((int) ($binding['brightness'] ?? 100)) / 100 * 255);
        $blink = ($binding['effect'] ?? '') === self::EFFECT_BLINK;
        $transition = (int) round(((float) ($binding['fade_sec'] ?? 0.2)) * 10);

        $segs = [];
        for ($ch = 1; $ch <= self::CHANNELS; $ch++) {
            $on = in_array($ch, $channels, true);
            $segs[] = [
                'id' => $ch - 1,
                'on' => $on,
                'col' => [$on ? $rgb : [0, 0, 0]],
                'fx' => $on && $blink ? 1 : 0,
                'sx' => $blink ? 160 : 128,
            ];
        }

        return [
            'on' => true,
            'bri' => max(1, min(255, $bri)),
            'transition' => max(0, min(300, $transition)),
            'udpn' => ['send' => false],
            'seg' => $segs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function idleState(bool $on, string $color, int $brightness): array
    {
        if (! $on) {
            return [
                'on' => false,
                'transition' => 5,
                'udpn' => ['send' => false],
            ];
        }

        $rgb = self::rgb($color);
        $bri = (int) round(max(1, min(100, $brightness)) / 100 * 255);
        $segs = [];
        for ($ch = 1; $ch <= self::CHANNELS; $ch++) {
            $segs[] = [
                'id' => $ch - 1,
                'on' => true,
                'col' => [$rgb],
                'fx' => 0,
                'sx' => 128,
            ];
        }

        return [
            'on' => true,
            'bri' => max(1, min(255, $bri)),
            'transition' => 5,
            'udpn' => ['send' => false],
            'seg' => $segs,
        ];
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    public static function rgb(string $color): array
    {
        return match ($color) {
            'red' => [255, 0, 0],
            'blue' => [0, 80, 255],
            'green' => [0, 255, 70],
            'yellow' => [255, 200, 0],
            'purple' => [180, 0, 255],
            'orange' => [255, 90, 0],
            'cold_white' => [200, 220, 255],
            default => [255, 255, 255],
        };
    }
}
