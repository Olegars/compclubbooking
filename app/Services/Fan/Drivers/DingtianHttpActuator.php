<?php

namespace App\Services\Fan\Drivers;

use App\Models\RelayBoard;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Dingtian / DT-R032 style HTTP CGI (common pattern):
 * GET /relay_cgi.cgi?type=0&relay=0&on=1&time=0
 *
 * Override path/query via board.meta if firmware differs.
 */
class DingtianHttpActuator implements FanActuatorInterface
{
    public function apply(RelayBoard $board, int $channel, int $power): void
    {
        if ($channel < 1 || $channel > 32) {
            throw new InvalidArgumentException("Invalid relay channel: {$channel}");
        }

        $meta = $board->meta ?? [];
        $path = (string) ($meta['path'] ?? '/relay_cgi.cgi');
        // Dingtian channels are often 0-based.
        $relayIndex = array_key_exists('zero_based', $meta)
            ? ((bool) $meta['zero_based'] ? $channel - 1 : $channel)
            : $channel - 1;
        $on = $power > 0 ? 1 : 0;

        $query = array_merge([
            'type' => 0,
            'relay' => $relayIndex,
            'on' => $on,
            'time' => 0,
        ], is_array($meta['query'] ?? null) ? $meta['query'] : []);

        $base = rtrim(sprintf('http://%s:%d', $board->host, (int) $board->port), '/');
        $url = $base.$path;

        $response = Http::timeout((float) config('fan.http_timeout', 2))
            ->get($url, $query);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Dingtian HTTP {$response->status()} for {$url} channel={$channel} on={$on}"
            );
        }
    }
}
