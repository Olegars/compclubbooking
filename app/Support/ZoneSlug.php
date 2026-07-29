<?php

namespace App\Support;

/**
 * Старые slug’и на карте → актуальные типы помещений.
 */
final class ZoneSlug
{
    private const ALIASES = [
        'solo' => 'singl',
        'standart' => 'singl',
        'standard' => 'singl',
        'bootkamp' => 'bootcamp',
        'botkamp' => 'bootcamp',
        'botkamp-profi' => 'bootcamp',
        'bootkamp-profi' => 'bootcamp',
        'bootcamp-profi' => 'bootcamp',
    ];

    public static function normalize(?string $slug): string
    {
        $slug = strtolower(trim((string) $slug));
        if ($slug === '') {
            return '';
        }

        return self::ALIASES[$slug] ?? $slug;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function normalizeMapConfig(array $config): array
    {
        if (! empty($config['zoneRects']) && is_array($config['zoneRects'])) {
            $config['zoneRects'] = array_map(function ($rect) {
                if (! is_array($rect)) {
                    return $rect;
                }
                $normalized = self::normalize($rect['type'] ?? '');
                if ($normalized !== '') {
                    $rect['type'] = $normalized;
                }

                return $rect;
            }, $config['zoneRects']);
        }

        return $config;
    }
}
