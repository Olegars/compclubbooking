<?php

namespace App\Support;

/**
 * Осознанные действия гостя. Фоновая автоматика, GSI и демоны сюда не входят.
 */
class UserFeatureCatalog
{
    public const SOURCE_PC = 'pc_shell';

    public const SOURCE_TV = 'tv_shell';

    public const SOURCE_WEB = 'web_cabinet';

    public const SOURCE_MOBILE = 'mobile_app';

    /**
     * Ключи, которые клиент может прислать сам.
     * Остальные пишутся только на сервере в момент успешного действия,
     * чтобы клик и API не посчитались дважды.
     *
     * @return list<string>
     */
    public static function clientMayReport(): array
    {
        return ['tv_app_launch'];
    }

    /**
     * @return list<array{
     *   key:string,
     *   title:string,
     *   icon:string,
     *   category:string,
     *   club_feature:?string,
     *   coalesce:bool
     * }>
     */
    public static function all(): array
    {
        return [
            self::row('voice_ai_f1', 'Голосовой ИИ (F1)', '🎙', 'ai_media', null, false),
            self::row('ghost_coach_toggle', 'Тумблер Ghost Coach', '🎧', 'ai_media', 'ghost_coach', false),
            self::row('instant_replay_hotkey', 'Ручной клип (F8)', '🎬', 'ai_media', 'instant_replay', false),
            self::row('clip_share_telegram', 'Клип в Telegram', '✈', 'ai_media', 'instant_replay', false),
            self::row('lucky_seat_claim', 'Lucky Seat: открыть кейс', '✦', 'esports', 'lucky_seat', false),
            self::row('lan_arena_challenge', 'Дуэль / битва', '⚔', 'esports', 'arena_duels', false),
            self::row('lfg_party_search', 'Кнопка «ПАТИ»', '🎮', 'esports', 'lfg', false),
            self::row('bounty_create', 'Охота за головой', '🎯', 'esports', 'lan_bounty', false),
            self::row('party_energy_contribute', 'Взнос в котёл пати', '⚡', 'esports', 'party_energy', false),
            self::row('fan_speed_manual', 'Скорость кулера', '🌀', 'comfort', null, true),
            self::row('light_color_manual', 'Цвет и яркость света', '💡', 'comfort', null, true),
            self::row('light_interactive_toggle', 'Интерактивный свет', '🌈', 'comfort', null, false),
            self::row('seat_transfer_request', 'Пересесть', '↔', 'service', 'seat_transfer', false),
            self::row('sos_call', 'SOS / помощь', '🆘', 'service', null, false),
            self::row('game_request', 'Заявка «Хочу игру»', '🕹', 'service', 'game_requests', false),
            self::row('shop_order_shell', 'Заказ бара с ПК', '🛒', 'service', 'shell_store', false),
            self::row('tv_app_launch', 'Приложение на TV', '📺', 'service', null, false),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            'all' => 'Все',
            'comfort' => 'Комфорт места',
            'esports' => 'Киберспорт и пати',
            'ai_media' => 'ИИ и клипы',
            'service' => 'Бар и самообслуживание',
        ];
    }

    public static function get(string $key): ?array
    {
        foreach (self::all() as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function keysForCategory(string $category): array
    {
        if ($category === '' || $category === 'all') {
            return array_column(self::all(), 'key');
        }

        return array_values(array_map(
            fn (array $row) => $row['key'],
            array_filter(self::all(), fn (array $row) => $row['category'] === $category)
        ));
    }

    /**
     * @return array{key:string, title:string, icon:string, category:string, club_feature:?string, coalesce:bool}
     */
    private static function row(
        string $key,
        string $title,
        string $icon,
        string $category,
        ?string $clubFeature,
        bool $coalesce,
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'icon' => $icon,
            'category' => $category,
            'club_feature' => $clubFeature,
            'coalesce' => $coalesce,
        ];
    }
};
