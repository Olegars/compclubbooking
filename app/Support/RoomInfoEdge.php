<?php

namespace App\Support;

/**
 * Выбор края комнаты для знака «?» — сторона, выходящая в проход.
 */
final class RoomInfoEdge
{
    /**
     * @param  array{x?:float|int,y?:float|int,w?:float|int,h?:float|int}  $rect
     * @param  list<array{x?:float|int,y?:float|int,w?:float|int,h?:float|int}>  $others
     * @return 'left'|'right'|'top'|'bottom'
     */
    public static function resolve(array $rect, array $others, ?string $override = null): string
    {
        $override = $override ? strtolower(trim($override)) : null;
        if (in_array($override, ['left', 'right', 'top', 'bottom'], true)) {
            return $override;
        }

        $x = (float) ($rect['x'] ?? 0);
        $y = (float) ($rect['y'] ?? 0);
        $w = (float) ($rect['w'] ?? 0);
        $h = (float) ($rect['h'] ?? 0);
        $band = 2.0;

        $scores = [
            'right' => self::blockedLength($others, $x + $w, $y, $band, $h, 'v'),
            'left' => self::blockedLength($others, $x - $band, $y, $band, $h, 'v'),
            'bottom' => self::blockedLength($others, $x, $y + $h, $w, $band, 'h'),
            'top' => self::blockedLength($others, $x, $y - $band, $w, $band, 'h'),
        ];

        // Меньше блокировки = свободнее проход. Ничья: right, bottom, left, top.
        $order = ['right', 'bottom', 'left', 'top'];
        $best = 'right';
        $bestScore = PHP_FLOAT_MAX;
        foreach ($order as $edge) {
            $score = $scores[$edge];
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $edge;
            }
        }

        return $best;
    }

    /**
     * @param  list<array{x?:float|int,y?:float|int,w?:float|int,h?:float|int}>  $others
     */
    private static function blockedLength(
        array $others,
        float $bx,
        float $by,
        float $bw,
        float $bh,
        string $axis
    ): float {
        $blocked = 0.0;
        foreach ($others as $o) {
            $ox = (float) ($o['x'] ?? 0);
            $oy = (float) ($o['y'] ?? 0);
            $ow = (float) ($o['w'] ?? 0);
            $oh = (float) ($o['h'] ?? 0);
            if ($ow < 0.5 || $oh < 0.5) {
                continue;
            }
            $ix0 = max($bx, $ox);
            $iy0 = max($by, $oy);
            $ix1 = min($bx + $bw, $ox + $ow);
            $iy1 = min($by + $bh, $oy + $oh);
            if ($ix1 <= $ix0 || $iy1 <= $iy0) {
                continue;
            }
            $blocked += $axis === 'v' ? ($iy1 - $iy0) : ($ix1 - $ix0);
        }

        return $blocked;
    }

    /**
     * @param  array<string, mixed>|null  $info
     * @return array{
     *   cpu:?string,gpu:?string,monitor:?string,
     *   screen_diagonal:?string,ps_model:?string,info_edge:?string
     * }
     */
    public static function normalizeInfo(?array $info): array
    {
        $info = $info ?? [];
        $edge = isset($info['info_edge']) ? strtolower(trim((string) $info['info_edge'])) : '';
        if ($edge === 'auto' || $edge === '') {
            $edge = null;
        }
        if ($edge !== null && ! in_array($edge, ['left', 'right', 'top', 'bottom'], true)) {
            $edge = null;
        }

        return [
            'cpu' => self::nullableString($info['cpu'] ?? null),
            'gpu' => self::nullableString($info['gpu'] ?? null),
            'monitor' => self::nullableString($info['monitor'] ?? null),
            'screen_diagonal' => self::nullableString($info['screen_diagonal'] ?? null),
            'ps_model' => self::nullableString($info['ps_model'] ?? null),
            'info_edge' => $edge,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));

        return $s === '' ? null : $s;
    }
}
