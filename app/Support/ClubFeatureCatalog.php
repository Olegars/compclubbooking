<?php

namespace App\Support;

/**
 * Реестр опциональных фич клуба: тумблер + поля настроек.
 */
class ClubFeatureCatalog
{
    public const GROUP_SHELL = 'shell';

    public const GROUP_STATIONS = 'stations';

    public const GROUP_ESPORTS = 'esports';

    public const GROUP_MARKETING = 'marketing';

    /**
     * @return list<array{
     *   key:string,
     *   title:string,
     *   description:string,
     *   group:string,
     *   icon:string,
     *   admin_path:?string,
     *   fields:list<array<string, mixed>>
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'qr_login',
                'title' => 'Вход по QR',
                'description' => 'QR на экране логина шелла: гость сканирует код в ЛК вместо PIN. PIN остаётся.',
                'group' => self::GROUP_SHELL,
                'icon' => '▣',
                'admin_path' => null,
                'fields' => [
                    self::intField('ttl_seconds', 'TTL кода, секунды', 120, 60, 600, 10, 'с', 'Через сколько секунд шелл запросит новый QR.'),
                ],
            ],
            [
                'key' => 'pc_throne',
                'title' => 'King of the Hill',
                'description' => 'Дневной трон конкретного ПК по фрагам GSI. Карточка King над QR и ♔ на дашборде.',
                'group' => self::GROUP_SHELL,
                'icon' => '♔',
                'admin_path' => null,
                'fields' => [
                    self::intField('min_kills', 'Минимум фрагов', 3, 1, 20, 1, '', 'С какого числа киллов за сессию можно забрать трон.'),
                ],
            ],
            [
                'key' => 'lfg',
                'title' => 'Пати в зале',
                'description' => 'Кнопка «ПАТИ» в шелле: соло указывает игру и ранг, облако ищет напарника в клубе.',
                'group' => self::GROUP_SHELL,
                'icon' => '🎮',
                'admin_path' => null,
                'fields' => [
                    self::intField('ttl_minutes', 'TTL поиска, минуты', 20, 5, 120, 1, 'мин'),
                    self::intField('rank_delta', 'Допуск по рангу, ступеней', 1, 0, 5, 1, '', '0 — только тот же ранг, 1 — соседние ступени.'),
                ],
            ],
            [
                'key' => 'lan_bounty',
                'title' => 'Охота за головами',
                'description' => 'Ставка депозитом или напитком за голову игрока на соседнем ПК. Закрывается по GSI.',
                'group' => self::GROUP_SHELL,
                'icon' => '🎯',
                'admin_path' => null,
                'fields' => [
                    self::intField('min_deposit', 'Мин. ставка, ₽', 50, 10, 1000, 10, '₽'),
                    self::intField('max_deposit', 'Макс. ставка, ₽', 5000, 50, 20000, 50, '₽'),
                ],
            ],
            [
                'key' => 'party_energy',
                'title' => 'Котёл пати',
                'description' => 'Общий котёл минут для брони на 2+ ПК. Капитан включает автоподпитку, сессия не рвёт матч.',
                'group' => self::GROUP_SHELL,
                'icon' => '⚡',
                'admin_path' => null,
                'fields' => [
                    self::intField('siphon_minutes', 'Порция из котла, минуты', 10, 5, 60, 1, 'мин'),
                    self::intField('trigger_seconds', 'Порог до конца, секунды', 90, 30, 300, 10, 'с', 'Когда остаток меньше порога и идёт матч — сифон из котла.'),
                ],
            ],
            [
                'key' => 'arena_duels',
                'title' => 'Арена дуэлей',
                'description' => 'Открытый мини-конкурс мастерства (ГК РФ ст. 1057): взнос с депозита в эскроу, приз обратно на депозит клуба без вывода на карту. Судейство по GSI. Не пари и не касса.',
                'group' => self::GROUP_SHELL,
                'icon' => '⚔',
                'admin_path' => null,
                'fields' => [
                    self::intField('min_entry_fee', 'Мин. взнос, ₽', 50, 10, 1000, 10, '₽'),
                    self::intField('max_entry_fee', 'Макс. взнос, ₽', 5000, 50, 20000, 50, '₽'),
                    self::intField('rake_percent', 'Сбор клуба, %', 10, 0, 20, 1, '%', 'Организационный сбор в момент закрытия матча. Приз = банк − сбор.'),
                    self::intField('invite_seconds', 'TTL адресного вызова, секунды', 60, 20, 180, 5, 'с'),
                    self::intField('open_ttl_minutes', 'TTL открытого котла, минуты', 5, 1, 30, 1, 'мин'),
                    self::intField('rank_delta', 'Допуск по рангу, ступеней', 3, 0, 8, 1, '', '0 — только тот же ранг LFG. Нет ранга — не блокируем.'),
                    self::intField('disconnect_seconds', 'Таймаут дисконнекта, секунды', 90, 30, 180, 5, 'с'),
                    self::intField('cooldown_seconds', 'Кулдаун вызова на тот же ПК, секунды', 120, 30, 600, 10, 'с'),
                    self::intField('first_to_cs', 'CS2: раундов до победы', 8, 1, 16, 1),
                    self::boolField('print_voucher', 'Печатать ваучер-поздравление', false, 'Кухонный слип победителю, без списания товара.'),
                ],
            ],
            [
                'key' => 'lucky_seat',
                'title' => 'Lucky Seat',
                'description' => 'Кейс в шелле за стрик побед GSI или за часы игры. Бонус / напиток / промокод RX на периферию.',
                'group' => self::GROUP_SHELL,
                'icon' => '✦',
                'admin_path' => '/admin/store/orders',
                'fields' => [
                    self::numberField('cooldown_hours', 'Кулдаун на игрока, часы', 3, 0.5, 24, 0.5, 'ч'),
                    self::numberField('playtime_hours', 'Кейс за игру, часы', 3, 0.5, 24, 0.5, 'ч'),
                    self::intField('match_streak', 'Стрик матчей', 2, 1, 10, 1),
                    self::intField('round_streak', 'Стрик раундов CS2', 5, 2, 20, 1),
                    self::intField('bonus_low', 'Бонус 1, ₽', 50, 10, 500, 5, '₽'),
                    self::intField('bonus_mid', 'Бонус 2, ₽', 75, 10, 500, 5, '₽'),
                    self::intField('bonus_high', 'Бонус 3, ₽', 100, 10, 500, 5, '₽'),
                    self::intField('promo_percent', 'Скидка промокода, %', 10, 5, 50, 1, '%'),
                    self::intField('promo_days', 'Срок промокода, дни', 30, 1, 90, 1, 'дн'),
                ],
            ],
            [
                'key' => 'ghost_coach',
                'title' => 'Ghost Coach',
                'description' => 'Шёпот в наушники по GSI зала. Игрок может выключить галкой в шелле; этот тумблер — мастер клуба. Пати CS2 — отдельная фича Coach Whisper.',
                'group' => self::GROUP_SHELL,
                'icon' => '🎧',
                'admin_path' => '/admin/ai-assistant',
                'fields' => [
                    self::intField('cooldown_seconds', 'Пауза между шёпотами, секунды', 28, 10, 120, 1, 'с'),
                ],
            ],
            [
                'key' => 'coach_whisper',
                'title' => 'Coach Whisper',
                'description' => 'Пати CS2: общий банк в freeze → эко-раунд или дроп AWP. Работает только при включённом Ghost Coach и брони на 2+ ПК.',
                'group' => self::GROUP_SHELL,
                'icon' => '💬',
                'admin_path' => '/admin/ai-assistant',
                'fields' => [
                    self::intField('eco_per_player', 'Порог эко на игрока, $', 2000, 500, 5000, 100, '$', 'Сумма банков пати ниже N×порог — одна фраза «эко-раунд».'),
                    self::intField('drop_donor_min', 'Лишний банк для дропа AWP, $', 5500, 4750, 16000, 50, '$'),
                ],
            ],
            [
                'key' => 'rage_smash',
                'title' => 'Rage-Smash',
                'description' => 'Удар по столу / key-mash: тикет, закладка NVR и оверлей с напитком. IMU мыши или падение K/D.',
                'group' => self::GROUP_SHELL,
                'icon' => '💥',
                'admin_path' => '/admin/incidents',
                'fields' => [
                    self::boolField('offer_drinks', 'Предложить напиток в оверлее', true, 'Список напитков бара в RageCalmOverlay.'),
                    self::boolField('nvr_mark', 'Закладка на NVR', true, 'Секундная метка hardware.abuse, даже если событие не заводили вручную.'),
                ],
            ],
            [
                'key' => 'cloud_saves',
                'title' => 'Cloud Saves',
                'description' => 'Пак конфигов игрока (sens CS2, cfg Valorant) едет с логином на любой ПК. Не клипы.',
                'group' => self::GROUP_SHELL,
                'icon' => '☁',
                'admin_path' => null,
                'fields' => [],
            ],
            [
                'key' => 'shell_store',
                'title' => 'Магазин с ПК',
                'description' => 'Каталог бара в шелле и заказ с терминала (в т.ч. галка «на пати»). Охота и Lucky Seat печатают слипы сами.',
                'group' => self::GROUP_SHELL,
                'icon' => '🛒',
                'admin_path' => '/admin/orders',
                'fields' => [],
            ],
            [
                'key' => 'seat_transfer',
                'title' => 'Пересадка',
                'description' => '«Пересесть» в шелле и ЛК: смена места во время сессии с доплатой или укорочением времени.',
                'group' => self::GROUP_SHELL,
                'icon' => '↔',
                'admin_path' => null,
                'fields' => [],
            ],
            [
                'key' => 'overlays',
                'title' => 'Shell-оверлеи',
                'description' => 'Картинка / видео / текст на idle ПК и TV. Live-счёт Clan Wars рисуется отдельно, если Clan Wars включены.',
                'group' => self::GROUP_SHELL,
                'icon' => '🖥',
                'admin_path' => '/admin/overlays',
                'fields' => [],
            ],
            [
                'key' => 'instant_replay',
                'title' => 'Instant Replay',
                'description' => 'Killcam / Reels с ПК: F8, автоклип по киллу CS2, клип на logout. Нужен ffmpeg на D:.',
                'group' => self::GROUP_SHELL,
                'icon' => '🎬',
                'admin_path' => null,
                'fields' => [
                    self::boolField('auto_on_kill', 'Автоклип по киллу CS2', true),
                    self::boolField('save_on_logout', 'Клип на logout', true),
                    self::intField('kill_seconds', 'Длина killcam, секунды', 12, 8, 40, 1, 'с'),
                    self::intField('kill_cooldown_sec', 'Пауза между killcam, секунды', 75, 20, 300, 5, 'с'),
                ],
            ],
            [
                'key' => 'rollback_markers',
                'title' => 'Rollback Markers',
                'description' => 'При сохранении Super Client архивирует хэши манифестов Steam/Epic и конфигов. После BSOD или сбоя драйвера админ откатывает станцию на проверенную ревизию в один клик.',
                'group' => self::GROUP_STATIONS,
                'icon' => '↩',
                'admin_path' => '/admin/dashboard',
                'fields' => [
                    self::boolField('crash_ticket', 'Тикет при BSOD / сбое драйвера', true, 'Нештатная перезагрузка пишет инцидент и кнопку отката на дашборде.'),
                    self::boolField('auto_verify', 'Автоподтверждение ревизии', false, 'Если выкл — техник жмёт «Проверена» после проверки второго ПК. Первая ревизия клуба всегда verified.'),
                    self::intField('keep', 'Сколько ревизий хранить', 24, 4, 60, 1, '', 'Старые pending/superseded чистятся. Проверенная не удаляется.'),
                ],
            ],
            [
                'key' => 'hardware_health',
                'title' => 'Hardware Health',
                'description' => 'Дребезг микрика мыши и залипание клавиши → тикет «Проверить свитч/микрик». Не путать с Rage-Smash.',
                'group' => self::GROUP_STATIONS,
                'icon' => '🖱',
                'admin_path' => '/admin/incidents',
                'fields' => [],
            ],
            [
                'key' => 'patch_cache',
                'title' => 'LAN P2P-патчи',
                'description' => 'Раздача свежих билдов Steam/Epic по VLAN (seed :8745). Выкл — нет seed/pull и ночного ingest.',
                'group' => self::GROUP_STATIONS,
                'icon' => '📡',
                'admin_path' => '/admin/dashboard',
                'fields' => [],
            ],
            [
                'key' => 'link_flap',
                'title' => 'Деградация кабеля',
                'description' => 'Падение линка 1 Гбит → 100 Мбит за смену пишет тикет «Заменить патч-корд» и жёлтую плитку flap.',
                'group' => self::GROUP_STATIONS,
                'icon' => '🔌',
                'admin_path' => '/admin/incidents',
                'fields' => [
                    self::intField('threshold', 'Событий за смену до тикета', 2, 1, 10, 1),
                ],
            ],
            [
                'key' => 'clan_wars',
                'title' => 'Clan Wars',
                'description' => 'Счёт CS2/Dota между зонами зала или локациями сети. Сайдбар Киберспорт → Clan Wars.',
                'group' => self::GROUP_ESPORTS,
                'icon' => '⚔',
                'admin_path' => '/admin/clan-wars',
                'fields' => [
                    self::intField('default_duration_minutes', 'Длительность по умолчанию, минуты', 60, 10, 240, 5, 'мин'),
                ],
            ],
            [
                'key' => 'tournaments',
                'title' => 'Турниры',
                'description' => 'Менеджер ивентов: сетка Single Elim, призы, lock каталога игр на ПК арены.',
                'group' => self::GROUP_ESPORTS,
                'icon' => '🏆',
                'admin_path' => '/admin/tournaments',
                'fields' => [],
            ],
            [
                'key' => 'achievements',
                'title' => 'Достижения',
                'description' => 'Квесты за часы, визиты и ночные сессии. Награда после закрытия брони.',
                'group' => self::GROUP_MARKETING,
                'icon' => '⭐',
                'admin_path' => '/admin/achievements',
                'fields' => [],
            ],
            [
                'key' => 'promocodes',
                'title' => 'Промокоды',
                'description' => 'Клубные коды /admin/promocodes: бонус или скидка в кабинете. Не RX-***** Lucky Seat.',
                'group' => self::GROUP_MARKETING,
                'icon' => '🎁',
                'admin_path' => '/admin/promocodes',
                'fields' => [],
            ],
            [
                'key' => 'game_requests',
                'title' => 'Заявки на игры',
                'description' => '«Хочу игру» из кабинета и шелла. Топ заявок помогает понять, что доустановить.',
                'group' => self::GROUP_MARKETING,
                'icon' => '📋',
                'admin_path' => '/admin/game-requests',
                'fields' => [],
            ],
            [
                'key' => 'review_bonuses',
                'title' => 'Бонусы за отзывы',
                'description' => 'Заявка из кабинета, сверка с Яндекс.Картами и 2ГИС, начисление бонуса.',
                'group' => self::GROUP_MARKETING,
                'icon' => '💬',
                'admin_path' => '/admin/bonuses',
                'fields' => [],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return [
            self::GROUP_SHELL => 'Шелл и зал',
            self::GROUP_STATIONS => 'Станции и образ',
            self::GROUP_ESPORTS => 'Киберспорт',
            self::GROUP_MARKETING => 'Маркетинг',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
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
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultSettings(string $key): array
    {
        $def = self::get($key);
        if (! $def) {
            return [];
        }
        $out = [];
        foreach ($def['fields'] as $field) {
            $out[$field['key']] = $field['default'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function sanitizeSettings(string $key, array $input): array
    {
        $def = self::get($key);
        if (! $def) {
            return [];
        }
        $out = [];
        foreach ($def['fields'] as $field) {
            $name = $field['key'];
            $raw = $input[$name] ?? $field['default'];
            $out[$name] = self::coerce($field, $raw);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public static function coerce(array $field, mixed $raw): mixed
    {
        $type = $field['type'] ?? 'int';
        if ($type === 'bool') {
            if (is_bool($raw)) {
                return $raw;
            }

            return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        }
        if ($type === 'number') {
            $value = is_numeric($raw) ? (float) $raw : (float) $field['default'];
            $min = (float) ($field['min'] ?? $value);
            $max = (float) ($field['max'] ?? $value);

            return max($min, min($max, $value));
        }
        $value = is_numeric($raw) ? (int) round((float) $raw) : (int) $field['default'];
        $min = (int) ($field['min'] ?? $value);
        $max = (int) ($field['max'] ?? $value);

        return max($min, min($max, $value));
    }

    /**
     * @return array<string, mixed>
     */
    private static function intField(
        string $key,
        string $label,
        int $default,
        int $min,
        int $max,
        int $step = 1,
        string $suffix = '',
        string $hint = '',
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'type' => 'int',
            'default' => $default,
            'min' => $min,
            'max' => $max,
            'step' => $step,
            'suffix' => $suffix,
            'hint' => $hint,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function numberField(
        string $key,
        string $label,
        float $default,
        float $min,
        float $max,
        float $step = 0.5,
        string $suffix = '',
        string $hint = '',
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'type' => 'number',
            'default' => $default,
            'min' => $min,
            'max' => $max,
            'step' => $step,
            'suffix' => $suffix,
            'hint' => $hint,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function boolField(string $key, string $label, bool $default, string $hint = ''): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => 'bool',
            'default' => $default,
            'hint' => $hint,
        ];
    }
}
