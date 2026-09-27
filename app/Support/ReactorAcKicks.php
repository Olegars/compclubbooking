<?php

namespace App\Support;

/**
 * Тексты кика для плагина CounterStrikeSharp. Вердикт облака, не сеть.
 */
class ReactorAcKicks
{
    /** @var array<string, string> */
    public const TEXTS = [
        'deny_no_token' => 'Нет пароля REACTOR AC. Скачайте клиент и получите connect.',
        'deny_unknown' => 'Пароль сервера недействителен.',
        'deny_steam' => 'Этот Steam не совпадает с сессией.',
        'deny_expired' => 'Пароль истёк. Нажмите «Переподключиться» в трее REACTOR AC.',
        'token_already_used' => 'Пароль уже использован. Нажмите «Переподключиться» в трее REACTOR AC.',
        'deny_stale' => 'Клиент REACTOR AC не на связи.',
        'deny_banned' => 'Доступ на сервер закрыт.',
        'deny_integrity' => 'Проверка целостности не пройдена: testsigning или виртуальная машина.',
        'deny_no_session' => 'Нет живой сессии на этом ПК.',
        'deny_no_steam' => 'Привяжите Steam: зайдите в CS2 на этом месте.',
    ];

    public static function text(string $reason): string
    {
        return self::TEXTS[$reason] ?? 'Вход на сервер отклонён.';
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::TEXTS;
    }
}
