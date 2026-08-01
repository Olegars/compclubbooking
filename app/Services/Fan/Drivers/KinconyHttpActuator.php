<?php

namespace App\Services\Fan\Drivers;

use App\Models\RelayBoard;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * KinCony KC868-H32B HTTP CGI:
 * GET /sw_ctl.cgi?Relay01=ON&postpwd=...
 */
class KinconyHttpActuator implements FanActuatorInterface
{
    public function apply(RelayBoard $board, int $channel, int $power): void
    {
        if ($channel < 1 || $channel > 32) {
            throw new InvalidArgumentException("Invalid relay channel: {$channel}");
        }

        $meta = $board->meta ?? [];
        $password = (string) ($meta['password'] ?? 'admin123');
        $path = (string) ($meta['path'] ?? '/sw_ctl.cgi');
        $relayKey = sprintf('Relay%02d', $channel);
        $state = $power > 0 ? 'ON' : 'OFF';

        $base = rtrim(sprintf('http://%s:%d', $board->host, (int) $board->port), '/');
        $url = $base.$path;

        $response = Http::timeout((float) config('fan.http_timeout', 2))
            ->get($url, [
                $relayKey => $state,
                'postpwd' => $password,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "KinCony HTTP {$response->status()} for {$url} channel={$channel} state={$state}"
            );
        }
    }
}
