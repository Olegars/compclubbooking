<?php

namespace App\Support;

/**
 * Справочник функций системы для админ-страницы «О системе».
 */
class SystemDocs
{
    /**
     * @return list<array{id:string,title:string,items:list<array{title:string,description:string,path:?string,audience:string}}}>
     */
    public static function sections(): array
    {
        return [
            [
                'id' => 'ops',
                'title' => 'Операции клуба',
                'items' => [
                    [
                        'title' => 'Дашборд',
                        'description' => 'Карта и статусы ПК, сводка по выручке магазина, активным сессиям и новым гостям. Поиск игрока по телефону, пополнение депозита с кассы, выдача бонусного времени.',
                        'path' => '/admin/dashboard',
                        'audience' => 'Админ',
                    ],
                    [
                        'title' => 'Очередь заказов',
                        'description' => 'Активные заказы бара и магазина. Смена статусов, скан кодов маркировки перед выдачей, глобальный HID-сканер для списания КМ в заказ.',
                        'path' => '/admin/orders',
                        'audience' => 'Админ',
                    ],
                    [
                        'title' => 'Склад',
                        'description' => 'Каталог товаров: цена, остаток, штрихкод, маркировка. Приёмка сканом и правка остатков. Supervisor+ может создавать товары и списывать единицы.',
                        'path' => '/admin/inventory',
                        'audience' => 'Админ / Supervisor+',
                    ],
                    [
                        'title' => 'Пересменка',
                        'description' => 'Закрытие текущей смены и открытие новой: пересчёт кассы и фактических остатков. Расхождения пишутся в инциденты и обновляют склад.',
                        'path' => '/admin/shifts/transfer',
                        'audience' => 'Админ',
                    ],
                    [
                        'title' => 'Архив смен',
                        'description' => 'История смен: кто открыл и закрыл, касса на старте и финише, время.',
                        'path' => '/admin/shifts/history',
                        'audience' => 'Админ',
                    ],
                ],
            ],
            [
                'id' => 'cyber',
                'title' => 'Киберспорт и маркетинг',
                'items' => [
                    [
                        'title' => 'Менеджер ивентов',
                        'description' => 'Список турниров с привязкой к играм. CRUD и смена статусов — в разработке (заготовка).',
                        'path' => '/admin/tournaments',
                        'audience' => 'Supervisor+',
                    ],
                    [
                        'title' => 'Маркетинг (промокоды)',
                        'description' => 'Создание промокодов: бонусные деньги или скидка, лимит активаций. Игрок применяет код в кабинете.',
                        'path' => '/admin/promocodes',
                        'audience' => 'Supervisor+ / Игрок',
                    ],
                    [
                        'title' => 'Достижения и трофеи',
                        'description' => 'Настройка достижений: часы игры, ночные визиты, число визитов. Периоды once / weekly / monthly. Награда на депозит или бонусный баланс начисляется после закрытия сессии.',
                        'path' => '/admin/achievements',
                        'audience' => 'Supervisor+ / Игрок',
                    ],
                    [
                        'title' => 'Заявки на игры',
                        'description' => 'Бесплатные заявки «Хочу игру» из кабинета и Shell. Топ по числу уникальных игроков помогает понять, что доустановить на диски. Антиспам: 1 заявка на название / 7 дней.',
                        'path' => '/admin/game-requests',
                        'audience' => 'Supervisor+ / Игрок / Shell',
                    ],
                    [
                        'title' => 'Бонусы за отзывы',
                        'description' => 'Сумма бонуса, витрина отзывов на сайте, заявки игроков. Сверка с Яндекс.Картами и 2ГИС (вручную и по cron).',
                        'path' => '/admin/bonuses',
                        'audience' => 'Supervisor+ / Игрок / Система',
                    ],
                    [
                        'title' => 'Реестр бонусов',
                        'description' => 'Журнал выдач бонусного времени операторами со статистикой за день и месяц.',
                        'path' => '/admin/bonus-logs',
                        'audience' => 'Supervisor+',
                    ],
                ],
            ],
            [
                'id' => 'economy',
                'title' => 'Экономика',
                'items' => [
                    [
                        'title' => 'Тарифы и пакеты',
                        'description' => 'Тарифы, правила цен по зонам и группам дней, календарные overrides, аддоны (доп. услуги). Используется при бронировании и расчёте цены.',
                        'path' => '/admin/tariffs',
                        'audience' => 'Supervisor+',
                    ],
                    [
                        'title' => 'Аналитика бизнеса',
                        'description' => 'Отчёты supervisor+: тепловая карта утилизации зон по часам и дням недели, когорты/LTV игроков и VIP (топ 20% spend), ABC/XYZ анализ склада бара.',
                        'path' => '/admin/analytics',
                        'audience' => 'Supervisor+',
                    ],
                    [
                        'title' => 'Налоги',
                        'description' => 'Расчёт УСН 6% по кварталам из taxable-транзакций (deposit/refund), фиксированные взносы ИП и 1% свыше 300 тыс.',
                        'path' => '/admin/taxes',
                        'audience' => 'Owner',
                    ],
                    [
                        'title' => 'Штат',
                        'description' => 'Список сотрудников из admins: роль, оклад/ставка, официальность. Пока только просмотр.',
                        'path' => '/admin/staff',
                        'audience' => 'Owner',
                    ],
                    [
                        'title' => 'Кошелёк игрока',
                        'description' => 'Депозит и бонусный баланс. Списание за бронь и магазин, пополнение с кассы или из кабинета (эквайринг — заглушка).',
                        'path' => null,
                        'audience' => 'Админ / Игрок',
                    ],
                ],
            ],
            [
                'id' => 'config',
                'title' => 'Конфигурация клуба',
                'items' => [
                    [
                        'title' => 'Топология залов (зоны)',
                        'description' => 'CRUD зон клуба (имя, slug, цвет). Основа тарификации и очереди ожидания места.',
                        'path' => '/admin/zones',
                        'audience' => 'Supervisor+',
                    ],
                    [
                        'title' => 'Редактор карты',
                        'description' => 'Визуальная карта зала: spaces, размещение ПК / TV / PS5. Сохраняет map_config клуба и синхронизирует computers и spaces.',
                        'path' => '/admin/map-builder',
                        'audience' => 'Supervisor+',
                    ],
                    [
                        'title' => 'Shell-оверлеи',
                        'description' => 'Экранные блоки терминала (картинка / видео / текст). Shell забирает активные оверлеи по позициям.',
                        'path' => '/admin/overlays',
                        'audience' => 'Supervisor+ / Shell',
                    ],
                    [
                        'title' => 'Игры и лицензии',
                        'description' => 'Каталог игр, Steam/игровые аккаунты, офферы клуба (free / per_seat_hour и др.). Shell берёт и освобождает аккаунты, обновляет VDF-кэш.',
                        'path' => '/admin/licenses',
                        'audience' => 'Supervisor+ / Shell',
                    ],
                    [
                        'title' => 'Видео-метки',
                        'description' => 'Интеграция с видеосервером (webhook; Hikvision/Trassir/Macroscop — заготовки). Метки на таймлайн при HID/SOS и тест отправки.',
                        'path' => '/admin/video-surveillance',
                        'audience' => 'Supervisor+ / Shell',
                    ],
                ],
            ],
            [
                'id' => 'security',
                'title' => 'Безопасность и сигналы',
                'items' => [
                    [
                        'title' => 'Инциденты',
                        'description' => 'Единая лента: задержки заказов, расхождения склада, SOS, HID-алерты, ручные записи. Ack и закрытие (resolve — supervisor+).',
                        'path' => '/admin/incidents',
                        'audience' => 'Админ / Shell / Система',
                    ],
                    [
                        'title' => 'SOS с терминала',
                        'description' => 'Игрок или shell отправляет SOS (периферия / помощь с входом / другое). Попадает в инциденты и бейджи сайдбара.',
                        'path' => '/admin/incidents',
                        'audience' => 'Shell → Админ',
                    ],
                    [
                        'title' => 'HID-алерты',
                        'description' => 'Снимки периферии и алерты: смена / отключение / нестабильность устройств. Опционально ставят видео-метки.',
                        'path' => '/admin/incidents',
                        'audience' => 'Shell → Админ',
                    ],
                    [
                        'title' => 'Вызов администратора',
                        'description' => 'Игрок или shell создаёт тикет; админка видит pending-вызовы и закрывает их.',
                        'path' => null,
                        'audience' => 'Игрок / Shell → Админ',
                    ],
                ],
            ],
            [
                'id' => 'player',
                'title' => 'Кабинет и сайт игрока',
                'items' => [
                    [
                        'title' => 'Личный кабинет',
                        'description' => 'Баланс, активные брони с таймером, заказы магазина, транзакции, прогресс достижений, статус заявки на бонус за отзыв.',
                        'path' => '/account/dashboard',
                        'audience' => 'Игрок',
                    ],
                    [
                        'title' => 'Настройки профиля',
                        'description' => 'Редактирование никнейма и email. Телефон зафиксирован как идентификатор входа.',
                        'path' => '/account/profile',
                        'audience' => 'Игрок',
                    ],
                    [
                        'title' => 'Магазин',
                        'description' => 'Витрина товаров. Заказ только при активной сессии за ПК; списание с депозита, доставка к месту сессии.',
                        'path' => '/shop',
                        'audience' => 'Игрок / Shell',
                    ],
                    [
                        'title' => 'Хочу игру',
                        'description' => 'Бесплатная заявка на тайтл, которого нет на дисках. Кнопка в кабинете; Shell — POST /api/shell/game-requests.',
                        'path' => '/account/dashboard',
                        'audience' => 'Игрок / Shell',
                    ],
                    [
                        'title' => 'Пополнение баланса',
                        'description' => 'Заглушка оплаты: кредит депозита + транзакция. Эквайринг ещё не подключён.',
                        'path' => null,
                        'audience' => 'Игрок',
                    ],
                    [
                        'title' => 'Очередь ожидания',
                        'description' => 'Встать в очередь / статус / выйти по зоне, когда нет свободных мест.',
                        'path' => null,
                        'audience' => 'Игрок',
                    ],
                    [
                        'title' => 'Лендинг и бронирование',
                        'description' => 'Публичная карта клуба, зоны, тарифы, availability ПК и игр, расчёт цены, бронь с PIN. Есть киоск `/terminal`.',
                        'path' => '/booking',
                        'audience' => 'Гость / Игрок',
                    ],
                    [
                        'title' => 'SMS-вход',
                        'description' => 'Вход по телефону: send-code / verify-code. Реальная SMS пока не подключена (тестовый код в логах).',
                        'path' => '/login',
                        'audience' => 'Игрок',
                    ],
                ],
            ],
            [
                'id' => 'shell',
                'title' => 'Shell (клиент на ПК)',
                'items' => [
                    [
                        'title' => 'Регистрация терминала',
                        'description' => 'Привязка ПК по HWID: check и register-terminal.',
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Логин сессии',
                        'description' => 'Телефон + PIN брони + terminal_id → активация сессии, остаток времени, баланс и settings_pack (Cloud Saves) для накатки конфигов на этот ПК.',
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Баланс и poll',
                        'description' => 'Периодический опрос баланса. При опросе закрываются просроченные сессии.',
                        'path' => null,
                        'audience' => 'Shell / Система',
                    ],
                    [
                        'title' => 'Игры на ПК',
                        'description' => 'Список игр, топы, запись запуска, take/free аккаунта, pause/unpause (новый PIN), обновление VDF-кэша.',
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Заявка на игру',
                        'description' => 'POST /api/shell/game-requests при активной сессии — игрок предлагает тайтл для установки.',
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Cloud Saves (настройки игрока)',
                        'description' => 'Индивидуальный пак конфигов (sens CS2, cfg Valorant и т.д.) в user_settings. GET/POST /api/shell/settings; на logout можно передать settings_pack — при следующем входе на любой ПК пак приходит в login.',
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Магазин с ПК',
                        'description' => 'Каталог, checkout и статус заказа прямо с терминала игрока.',
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Logout',
                        'description' => 'Завершение активной брони на этом ПК, освобождение игровых аккаунтов; опционально сохраняет settings_pack в облако клуба.',
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                ],
            ],
            [
                'id' => 'sessions',
                'title' => 'Сессии и биллинг',
                'items' => [
                    [
                        'title' => 'Активация и тайминг',
                        'description' => 'Ранний старт сдвигает ends_at с сохранением оплаченной длительности. Опоздание сверх grace — no-show и отмена.',
                        'path' => null,
                        'audience' => 'Система / Shell',
                    ],
                    [
                        'title' => 'Автозакрытие сессий',
                        'description' => 'Команда reactor:update-statuses каждую минуту закрывает просроченные брони, обновляет busy/available ПК и снимает no-show.',
                        'path' => null,
                        'audience' => 'Система',
                    ],
                    [
                        'title' => 'Контроль качества заказов',
                        'description' => 'reactor:check-quality каждую минуту создаёт инцидент late_order, если заказ висит pending дольше 5 минут.',
                        'path' => null,
                        'audience' => 'Система',
                    ],
                    [
                        'title' => 'Сверка отзывов',
                        'description' => 'reactor:check-reviews ежедневно в 10:00 сверяет pending-заявки с Яндекс.Картами и 2ГИС.',
                        'path' => null,
                        'audience' => 'Система',
                    ],
                ],
            ],
            [
                'id' => 'auth-admin',
                'title' => 'Доступ в админку',
                'items' => [
                    [
                        'title' => 'Вход оператора',
                        'description' => 'Email/password через guard admin. Роли: admin, supervisor, owner — разные разделы сайдбара.',
                        'path' => '/admin/login',
                        'audience' => 'Админ',
                    ],
                ],
            ],
        ];
    }
}
