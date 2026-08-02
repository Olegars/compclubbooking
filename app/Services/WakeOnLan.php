<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Нормализация MAC + (опционально) локальная отправка magic packet.
 * В проде WOL шлёт MikroTik в LAN; облако этот send() не вызывает.
 */
class WakeOnLan
{
    public function send(string $mac, int $port = 9): bool
    {
        $normalized = $this->normalizeMac($mac);
        if ($normalized === null) {
            Log::warning('WOL: invalid MAC', ['mac' => $mac]);

            return false;
        }

        $packet = $this->buildPacket($normalized);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            "udp://255.255.255.255:{$port}",
            $errno,
            $errstr,
            2,
            STREAM_CLIENT_CONNECT
        );

        if ($fp === false) {
            Log::warning('WOL: socket failed', ['mac' => $normalized, 'errno' => $errno, 'error' => $errstr]);

            return false;
        }

        stream_set_timeout($fp, 2);
        $written = @fwrite($fp, $packet);
        fclose($fp);

        if ($written === false || $written < strlen($packet)) {
            Log::warning('WOL: fwrite failed', ['mac' => $normalized]);

            return false;
        }

        Log::info('WOL: magic packet sent', ['mac' => $normalized, 'port' => $port]);

        return true;
    }

    public function normalizeMac(string $mac): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');
        if (strlen($hex) !== 12) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    private function buildPacket(string $normalizedMac): string
    {
        $hex = str_replace(':', '', $normalizedMac);
        $macBytes = pack('H*', $hex);

        return str_repeat(chr(0xFF), 6).str_repeat($macBytes, 16);
    }
}
