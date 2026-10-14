<?php

namespace App\Services\Fire;

use App\Models\FirePanel;
use RuntimeException;

/**
 * Прозрачный UDP С2000-Ethernet. Говорим с пультом С2000М по RS-232,
 * линию КДЛ вторым мастером не занимаем.
 */
class OrionPanelClient
{
    public function poll(FirePanel $panel, FireAlarmService $alarms): void
    {
        $host = trim((string) $panel->host);
        if (! $panel->enabled || $host === '') {
            return;
        }

        $port = (int) ($panel->port ?: 40000);
        $address = (int) ($panel->panel_address ?: 1);
        $socket = $this->open($host, $port);

        try {
            if ($panel->reset_pending) {
                $this->exchange($socket, OrionFrame::build($address, OrionFrame::CMD_RESET_ALARM, "\x00"));
                $panel->reset_pending = false;
                $panel->last_reset_at = now();
            }

            for ($i = 0; $i < 20; $i++) {
                $raw = $this->exchange($socket, OrionFrame::build($address, OrionFrame::CMD_READ_EVENT));
                $frame = OrionFrame::parse($raw);
                if ($frame === null) {
                    throw new RuntimeException('Пульт ответил кадром без контрольной суммы.');
                }
                $event = OrionFrame::event($frame);
                if ($event === null) {
                    break;
                }
                $alarms->note((int) $panel->club_id, $event['device'], $event['loop'], $event['code']);
            }

            $panel->fail_count = 0;
            $panel->last_ok_at = now();
            $panel->last_error = null;
            $panel->save();
        } catch (\Throwable $e) {
            $panel->fail_count = min(65000, (int) $panel->fail_count + 1);
            $panel->last_error = mb_substr($e->getMessage(), 0, 250);
            $panel->save();
        } finally {
            fclose($socket);
        }
    }

    /**
     * @return resource
     */
    private function open(string $host, int $port)
    {
        $socket = @stream_socket_client(
            'udp://'.$host.':'.$port,
            $errno,
            $errstr,
            1
        );
        if (! is_resource($socket)) {
            throw new RuntimeException($errstr !== '' ? $errstr : 'Нет UDP до С2000-Ethernet.');
        }
        stream_set_timeout($socket, 0, 400000);

        return $socket;
    }

    /**
     * @param  resource  $socket
     */
    private function exchange($socket, string $packet): string
    {
        $written = @fwrite($socket, $packet);
        if ($written === false || $written < strlen($packet)) {
            throw new RuntimeException('Кадр на С2000-Ethernet не ушёл.');
        }

        $reply = @fread($socket, 512);
        if (! is_string($reply) || $reply === '') {
            throw new RuntimeException('Пульт не ответил.');
        }

        return $reply;
    }
}
