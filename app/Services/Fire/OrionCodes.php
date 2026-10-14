<?php

namespace App\Services\Fire;

/**
 * Коды событий протокола «Орион» (приложение к пакету управления Болид).
 * 110 — «сброс тревоги»: сирена снята, дым в камере мог остаться.
 */
final class OrionCodes
{
    /** @var list<int> */
    public const SMOKE = [37, 44, 265];

    /** @var list<int> */
    public const CLEAR = [24, 35, 39];

    public const RESET_ACK = 110;

    public static function isSmoke(int $code): bool
    {
        return in_array($code, self::SMOKE, true);
    }

    public static function isClear(int $code): bool
    {
        return in_array($code, self::CLEAR, true);
    }

    public static function known(int $code): bool
    {
        return self::isSmoke($code) || self::isClear($code) || $code === self::RESET_ACK;
    }
}
