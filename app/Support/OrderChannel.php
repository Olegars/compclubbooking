<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Откуда оформлен заказ бара: сайт, приложение, терминал клуба, шелл ПК.
 */
final class OrderChannel
{
    public const SITE = 'site';
    public const APP = 'app';
    public const TERMINAL = 'terminal';
    public const SHELL = 'shell';

    /** @var list<string> */
    public const ALL = [self::SITE, self::APP, self::TERMINAL, self::SHELL];

    public static function isClientApp(?Request $request = null): bool
    {
        $ua = (string) ($request?->userAgent() ?? '');

        return str_contains($ua, 'CompClubClient');
    }

    /**
     * Сайт / приложение / киоск. Шелл пишет канал сам при insert.
     */
    public static function fromShopRequest(Request $request): string
    {
        if ($request->is('api/terminal/shop/checkout') || $request->input('order_type') === 'kiosk') {
            return self::TERMINAL;
        }

        return self::isClientApp($request) ? self::APP : self::SITE;
    }

    public static function label(?string $channel): string
    {
        return match ($channel) {
            self::SITE => 'Сайт',
            self::APP => 'Приложение',
            self::TERMINAL => 'Терминал',
            self::SHELL => 'Шелл',
            default => '',
        };
    }
}
