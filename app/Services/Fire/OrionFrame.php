<?php

namespace App\Services\Fire;

/**
 * Кадр линии «Орион»: адрес, длина, команда, данные, дополнение суммы до 0.
 * С2000-Ethernet в прозрачном режиме кладёт эти байты на RS-232 пульта.
 */
final class OrionFrame
{
    public const CMD_READ_EVENT = 0x01;

    /** 21 — «сброс тревоги» в таблице команд Орион Про. Не снятие и не отключение шлейфа. */
    public const CMD_RESET_ALARM = 0x15;

    public static function build(int $address, int $command, string $data = ''): string
    {
        $body = chr($command & 0xFF).$data;
        $packet = chr($address & 0xFF).chr(strlen($body) & 0xFF).$body;
        $sum = 0;
        $length = strlen($packet);
        for ($i = 0; $i < $length; $i++) {
            $sum = ($sum + ord($packet[$i])) & 0xFF;
        }

        return $packet.chr((0x100 - $sum) & 0xFF);
    }

    /**
     * @return array{addr:int,cmd:int,data:string}|null
     */
    public static function parse(string $raw): ?array
    {
        if (strlen($raw) < 4) {
            return null;
        }

        $length = ord($raw[1]);
        $total = 2 + $length + 1;
        if ($length < 1 || strlen($raw) < $total) {
            return null;
        }

        $packet = substr($raw, 0, $total);
        $sum = 0;
        $size = strlen($packet);
        for ($i = 0; $i < $size; $i++) {
            $sum = ($sum + ord($packet[$i])) & 0xFF;
        }
        if ($sum !== 0) {
            return null;
        }

        return [
            'addr' => ord($packet[0]),
            'cmd' => ord($packet[2]),
            'data' => substr($packet, 3, $length - 1),
        ];
    }

    /**
     * Событие из ответа на запрос: код, адрес датчика на ДПЛС, адрес прибора.
     * Один байт 0x00 — новых событий нет.
     *
     * @param  array{addr:int,cmd:int,data:string}  $frame
     * @return array{code:int,loop:int,device:int}|null
     */
    public static function event(array $frame): ?array
    {
        $data = $frame['data'];
        if ($data === '' || $data === "\x00") {
            return null;
        }
        if (strlen($data) < 3) {
            return null;
        }

        $code = ord($data[0]);
        if ($code === 0) {
            return null;
        }

        $device = ord($data[2]);
        if ($device === 0) {
            $device = (int) $frame['addr'];
        }

        return [
            'code' => $code,
            'loop' => ord($data[1]),
            'device' => $device,
        ];
    }
}
