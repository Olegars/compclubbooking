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
                        'description' => "Активные заказы бара и магазина (в т.ч. с Shell ПК). Смена статусов, скан кодов маркировки перед выдачей, глобальный HID-сканер для списания КМ в заказ.\n\nЗвук при новом заказе: на /admin/orders очередь опрашивается ~каждые 7 с; при появлении нового id играется /sounds/notification.mp3 и тост «Новый заказ в очереди» (нужна открытая вкладка очереди; браузер может требовать жест пользователя для Audio). В сайдбаре — бейдж pending_orders.\n\nАвтопечать чек-ордера ESC/POS (один Ethernet-принтер бара, TCP :9100): при создании заказа (Shell/магазин) в очередь order_kitchen_prints кладётся слип вида «ПК-08 | #123» + строки «2x Энергетик». Кнопки «Печать» нет — сразу в очередь. Облако само на принтер не ходит: LAN-агент scripts/kitchen-print-agent.ps1 pull’ит GET /api/kitchen/print-targets?token=… и шлёт raw ESC/POS, затем POST /api/kitchen/print-applied. Env: KITCHEN_PRINT_ENABLED, KITCHEN_PRINT_RELAY_TOKEN (или CLUB_WOL_RELAY_TOKEN), на агенте — KITCHEN_API_BASE / KITCHEN_PRINT_TOKEN / KITCHEN_PRINTER_HOST / KITCHEN_PRINTER_PORT. Не путать с копией фискального чека в /admin/transactions.\n\nСтраховка: reactor:check-quality → инцидент late_order, если pending дольше 5 минут (см. «Контроль качества заказов»).\n\nДоставка: в заказе pc_name = ПК активной сессии гостя на момент оформления (бар несёт туда).",
                        'path' => '/admin/orders',
                        'audience' => 'Админ / Бар / Техник',
                    ],
                    [
                        'title' => 'Склад',
                        'description' => 'Каталог, приёмка сканом, списание и угощение с причиной (просрочка / бой / комп) — пишется в journal stock_movements, чтобы не всплывало как «кража» на пересменке. КМ — по DataMatrix; немеченое — количеством.\n\nСебестоимость: при приёмке указывается закупочная цена (unit_cost) и поставщик → создаётся партия inventory_batches (FIFO), пересчитывается средневзвешенная cost_price на карточке, при поставщике и цене > 0 — открытый счёт в долгах. При продаже/списании себестоимость (COGS) списывается FIFO и пишется в meta движения.\n\nМин. остаток (min_stock): если задан и stock ≤ порога, reactor:check-quality создаёт инцидент low_stock (без дублей, пока не закрыт).',
                        'path' => '/admin/inventory',
                        'audience' => 'Админ / Supervisor+',
                    ],
                    [
                        'title' => 'Поставщики, долги и маржа',
                        'description' => 'Карточки поставщиков (ИНН, отсрочка payment_terms_days). Приёмка со скана/склада автоматически копит долг; можно добавить ручной счёт и вносить оплаты частями (open → partial → paid). Просрочка считается по due_at. Вкладка «Маржа» — price vs cost_price по каталогу. Excel не используется — всё в админке.',
                        'path' => '/admin/suppliers',
                        'audience' => 'Supervisor+',
                    ],
                    [
                        'title' => 'Пересменка',
                        'description' => 'Закрытие смены: касса + факт остатков. Расхождение по немеченому требует причину и пишется в stock_movements. Маркированные позиции правятся только списанием КМ, не пересчётом.',
                        'path' => '/admin/shifts/transfer',
                        'audience' => 'Админ',
                    ],
                    [
                        'title' => 'Архив смен',
                        'description' => 'История смен: кто открыл и закрыл, касса на старте и финише, время.',
                        'path' => '/admin/shifts/history',
                        'audience' => 'Админ',
                    ],
                    [
                        'title' => 'Транзакции и копии чеков',
                        'description' => "Отдельный журнал фискализуемых операций (/admin/transactions).\n\nВ списке: телефон и имя гостя, тип (пополнение / бронь / магазин / возврат), сумма, статус чека (success / pending / deferred / void / error / skipped), признак «+Email/SMS» если клиент попросил отправку, ссылка ОФД (или демо-заглушка при выключенной кассе).\n\ndeferred = бронь оплачена с баланса, чек ждёт вход на ПК; void = отмена с возвратом до оказания услуги.\n\nПоиск по телефону/имени, фильтр по типу и статусу.\nКнопка «Напечатать» открывает окно «КОПИЯ ЧЕКА» с QR и реквизитами первичной фискализации — без повторного RegisterCheck и без новой оплаты. Для заглушек (касса выкл.) печать копии ОФД недоступна.\n\nСхема чеков: пополнение → аванс; бронь → полный расчёт при login/no-show; магазин с баланса → полный расчёт сразу.",
                        'path' => '/admin/transactions',
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
                'title' => 'Экономика и биллинг',
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
                        'title' => 'Кошелёк и эквайринг',
                        'description' => "Депозит и бонусный баланс. Списание за бронь и магазин, пополнение с кассы или из кабинета/шелла через ЮKassa (карта / СБП).\n\nМодель 54‑ФЗ:\n• пополнение кошелька — чек «аванс» сразу;\n• бронь / апгрейд — деньги с кошелька сразу, чек «полный расчёт» отложен (fiscal_status=deferred) до входа на ПК (shell login) или no-show с удержанием оплаты; в наименовании чека указывается ПК;\n• отмена брони с возвратом до входа — deferred → void, без чека;\n• магазин — полный расчёт сразу при покупке.\nПока FISCAL_ENABLED=false — после settle отдаётся демо-заглушка (/receipt/stub/{id}).",
                        'path' => '/admin/transactions',
                        'audience' => 'Админ / Игрок / Shell',
                    ],
                    [
                        'title' => 'Электронный чек: UI оплаты',
                        'description' => "Единая логика перед «Оплатить» / «Подтвердить» (сайт, ЛК, шелл; магазин/бронь/терминал — по тому же шаблону):\n• галочка «Отправить чек на Email/SMS» по умолчанию снята;\n• плашка: «Нажимая «…», вы соглашаетесь получить чек в виде QR-кода на экране»;\n• с галочкой — ОФД дублирует чек на контакт аккаунта (FiscalService → ClientAddress в KkmServer);\n• без галочки — только QR на экране и в логе транзакций профиля.",
                        'path' => '/account/dashboard',
                        'audience' => 'Терминал / Сайт / App / Shell',
                    ],
                    [
                        'title' => 'Success Screen и QR в профиле',
                        'description' => "После подтверждения оплаты пополнения — окно с QR по fiscal_receipt_url (ОФД или демо-заглушка).\n\nЗакрывающий чек брони появляется после авторизации на ПК: шелл показывает попап с QR, текстом «Если нужен бумажный чек — обратитесь к администратору» и кнопкой «Закрыть». В ЛК кнопка «Чек» станет доступна, когда fiscal_status перейдёт из deferred в success/skipped.",
                        'path' => '/account/dashboard',
                        'audience' => 'Терминал / Сайт / App / Shell',
                    ],
                    [
                        'title' => 'Публичная оферта и акцепт',
                        'description' => "Юридический фундамент расчётов без администратора через автоматы.\n\nСтраница /legal/offer. При SMS-входе пишется users.offer_accepted_at.\n\nКлючевой пункт: покупатель соглашается на кассовый чек в электронном виде (QR на экране и/или в ЛК); SMS/Email — только если выбрана опция при оплате.",
                        'path' => '/legal/offer',
                        'audience' => 'Юридика / Сайт / Shell / App',
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
                        'description' => "Каталог игр, Steam/игровые аккаунты, офферы клуба (free / per_seat_hour и др.). Shell: take/free аккаунта, запись запуска, обновление VDF-кэша на станции (machine cache, pivot аккаунт×ПК).\n\nЭто облачный пул лицензий для уже установленного софта на диске/образе ПК — не управление diskless-образами и не Steam Caching / Game Center. Интеграций CCBoot / SENET Boot / NDEV / iCafemenu нет: переключение OS-образа, назначение кэш-сервера и централизованный апдейт игр из админки не реализованы.",
                        'path' => '/admin/licenses',
                        'audience' => 'Supervisor+ / Shell',
                    ],
                    [
                        'title' => 'Питание ПК: WOL и выключение',
                        'description' => "Уже есть (без IPMI / Smart PDU):\n\n• ComputerPowerService: desired on/off по активным и ближайшим броням (warmup CLUB_POWER_WARMUP_MINUTES, по умолчанию 30 мин до старта).\n• Онлайн = свежий heartbeat шелла (last_seen_at; stale CLUB_POWER_HEARTBEAT_STALE_SECONDS).\n• Состояния: on / off / booting / error (таймаут WOL или нет MAC).\n• Wake-on-LAN: облако само magic packet в LAN не шлёт. MikroTik (или LAN-агент) pull’ит очередь GET /api/power/wol-targets?token=… и подтверждает POST /api/power/wol-sent (токен CLUB_WOL_RELAY_TOKEN). MAC приходит с шелла в /api/shell/power/heartbeat.\n• После logout / idle при desired=off сервер отдаёт power_action=shutdown|reboot; Shell (Qt) принимает команду. reboot — через shutdown /r; shutdown в агенте сейчас stub (отключён для отладки) — смотри ProcessManager::applyPowerAction.\n• Дашборд: снимок питания ПК, в т.ч. «Ошибка WOL».\n• Cron reactor:update-statuses пересчитывает desired/state вместе со статусами сессий.\n\nНет: IPMI, Smart PDU, кнопка «принудительно выключить/перезагрузить» из админки поверх расписания, интеграция с diskless-бутлоадерами.",
                        'path' => '/admin/dashboard',
                        'audience' => 'Supervisor+ / Shell / MikroTik',
                    ],
                    [
                        'title' => 'Diskless / кэш игр — статус',
                        'description' => "Не реализовано: управление бездисковой загрузкой из админки (образ OS, локальный кэш-сервер), синхронизация списков игр/путей из единого diskless-хранилища, контроль Steam Caching / Game Center как локального сервера обновлений, драйверы CCBoot / SENET / NDEV / iCafemenu.\n\nРядом по смыслу уже есть: каталог и офферы (/admin/licenses), выдача Steam-аккаунтов и VDF на ПК, заявки «Хочу игру», WOL по брони (см. «Питание ПК»). Станции предполагаются уже с установленным образом/диском; booking не оркестрирует PXE/iSCSI.",
                        'path' => '/admin/licenses',
                        'audience' => 'Supervisor+ / Техник',
                    ],
                    [
                        'title' => 'Гостевой Wi-Fi (идентификация)',
                        'description' => "Цель: пускать в Wi-Fi только авторизованных игроков (телефон из аккаунта), лог MAC↔user — задел под идентификацию публичного доступа.\n\nСхема (интернета априори нет):\n1) Телефон в SSID Hotspot — полный интернет закрыт; в walled garden белый список: этот хост (APP_URL), как минимум /wifi/* и /login + SMS API.\n2) QR на наклейке/стойке → GET /wifi/join?station={WIFI_STATION_CODE}&mac=$(mac)&ip=$(ip) (mac/ip подставляет MikroTik Hotspot login-link).\n3) Гость логинится по SMS (если ещё нет сессии) → «Открыть интернет» → POST /api/wifi/authorize → запись wifi_access_sessions (pending).\n4) MikroTik pull: GET /api/wifi/grant-targets?token=… → список MAC на grant/revoke; после применения POST /api/wifi/grant-applied { grant_ids, revoke_ids, enrich? }.\n5) Роутер добавляет MAC в hotspot bypass / ip binding — интернет открыт. Срок WIFI_SESSION_HOURS.\n\nEnv: WIFI_ACCESS_ENABLED, WIFI_STATION_CODE, WIFI_SESSION_HOURS, WIFI_RELAY_TOKEN (или CLUB_WOL_RELAY_TOKEN).\nНе путать с isolate ПК/TV (другая очередь /api/power/isolate-*). Админки списка сессий пока нет — только API + таблица.",
                        'path' => '/wifi/join',
                        'audience' => 'Техник / Игрок / MikroTik',
                    ],
                    [
                        'title' => 'Health-check / мониторинг — что есть',
                        'description' => "Отдельной страницы «мониторинг клуба» (Ping WAN, CPU/RAM сервера, баланс SMS, uptime W5100) в booking нет и не планируется как Zabbix-замена. Живём на облаке + резервный интернет (dual-WAN / LTE на MikroTik) — канал важнее offline-edge.\n\nУже есть по контурам:\n• ПК / Shell online — power heartbeat (~30 с), last_seen_at, stale по CLUB_POWER_HEARTBEAT_STALE_SECONDS; снимок питания и ошибки WOL на дашборде.\n• Сессия на шелле — poll balance/heartbeat (~8 с); падение session_active / logout закрывает UI.\n• Вентиляция — desired на сервере, apply→ack с Shell; thermal CPU°C с ПК для авто-скорости; shared-реле через LAN-агент. Отдельного «W5100 не пингуется» инцидента нет: смотрим, что шелл/агент живы и applied доходит.\n• Качество сервиса — reactor:check-quality: late_order, low_stock → /admin/incidents.\n• SOS / HID / вызов админа — лента инцидентов и бейджи сайдбара.\n• Автозакрытие сессий / питание — reactor:update-statuses каждую минуту.\n\nНет в продукте: ICMP ping провайдера, CPU/RAM хоста booking, опрос баланса SMS-шлюза, watchdog desired≠applied по W5100. WAN/failover — на стороне MikroTik или внешнего Uptime; SMS-баланс — когда шлюз боевой (сейчас SMS-вход ещё тестовый код в логах).",
                        'path' => '/admin/dashboard',
                        'audience' => 'Supervisor+ / Техник',
                    ],
                    [
                        'title' => 'Видео-метки',
                        'description' => 'Интеграция с видеосервером (webhook; Hikvision/Trassir/Macroscop — заготовки). Метки на таймлайн при HID/SOS и тест отправки.',
                        'path' => '/admin/video-surveillance',
                        'audience' => 'Supervisor+ / Shell',
                    ],
                    [
                        'title' => 'ИИ-ассистент',
                        'description' => "Голосовой компаньон Shell (F1) и персональное приветствие при логине. Настройки на клуб: вкл/выкл, LLM (DeepSeek или OpenAI), ключи LLM и OpenAI (STT Whisper + TTS), base URL и модели, голос TTS (alloy/echo/fable/onyx/nova/shimmer), макс. длина ответа, системные промпты F1 и приветствия с плейсхолдерами {{club}}, {{player}}, {{game}}, {{pc}}, {{time}}, {{visit_line}}, {{games}}, {{max_chars}}.\n\nКлючи в админке шифруются в БД; пустое поле при сохранении не затирает; «очистить» возвращает fallback на .env. Аварийный выключатель: AI_ASSISTANT_ENABLED в .env (должен быть true вместе с тумблером в админке). Без ключей (ни БД, ни .env) пайплайн не стартует.\n\nShell: POST /api/shell/ai-assistant (аудио → STT → LLM → TTS), POST /api/shell/voice-greeting (контекст игрока → LLM → TTS в колонки лобби).",
                        'path' => '/admin/ai-assistant',
                        'audience' => 'Supervisor+ / Shell',
                    ],
                    [
                        'title' => 'Вентиляция: железо и скорости',
                        'description' => "Личный вентилятор места (SpaceFan) сидит на плате W5100 (RelayBoard): host + path-порт (по умолчанию 30000). URL команды: http://{host}/{port}/{cmd} — порт это сегмент пути, TCP обычно :80.\n\nДва канала каскада K1+K2 (пары 1+2, 3+4 … 15+16):\n• скорость 1 (night / 120V) — K1 OFF, K2 OFF\n• скорость 2 (mid / 170V) — K1 ON, K2 OFF\n• скорость 3 (high / 220V) — K1 OFF, K2 ON\n\nПолного электрического OFF на двух CO-реле нет: «выкл» = night. Прыжок 1↔3 идёт через mid ~2.5 с, чтобы не бить контакторы. На комнату (space) до 2 личных вентиляторов.",
                        'path' => '/admin/fans',
                        'audience' => 'Supervisor+ / Shell',
                    ],
                    [
                        'title' => 'Вентиляция: кто крутит реле',
                        'description' => "Облако (booking) только считает desired_power и факты (сессия / CPU°C / manual). Физический HTTP на W5100 делает Shell по LAN — сервер в интернет до платы не ходит.\n\nPC Shell (Qt): опрос fan state, ручные 50/75/100%, thermal report, apply → ack.\nTV Shell (APK): привязка пары каналов в Setup (discover → ТЕСТ mid ~2с → ПРИВЯЗАТЬ), те же API /api/shell/fan/*.\n\nРежимы: auto (по сессии и термопорогам), force_on, force_off(=night). Пороги thermal_on_c / thermal_off_c (дефолт 75 / 65). Пустая комната сбрасывает force_on в auto.",
                        'path' => '/admin/fans',
                        'audience' => 'Supervisor+ / Shell / TV',
                    ],
                    [
                        'title' => 'Вентиляция: общие приток/вытяжка',
                        'description' => "SharedFan (supply / exhaust) — общие вентиляторы клуба. К ним мапятся личные SpaceFan; нагрузка пересчитывается (SharedFanControlService) от desired_power мест.\n\nАктуация shared-реле — агент в LAN по токену FAN_SHARED_RELAY_TOKEN (или CLUB_WOL_RELAY_TOKEN). Админка: платы, личные вентиляторы, shared, карты привязок.",
                        'path' => '/admin/fans',
                        'audience' => 'Supervisor+ / Система',
                    ],
                ],
            ],
            [
                'id' => 'tv-shell',
                'title' => 'TV Shell (Android TV / приставка)',
                'items' => [
                    [
                        'title' => 'Назначение',
                        'description' => "APK ru.compclub.tvshell — киоск на Android TV / Google TV / ТВ-приставке (не PC Qt-шелл).\n\nIdle: логин + оверлеи (6 блоков как на PC: CAM/DAT/INF).\nСессия: таймер, баланс, лаунчер приложений, SOS, продление по QR, HDMI если прошивка отдаёт входы.\n\nСеть без сессии режется через MikroTik (MAC/IP isolate); с сессией — restore.",
                        'path' => null,
                        'audience' => 'Supervisor+ / TV',
                    ],
                    [
                        'title' => 'Установка APK на устройство',
                        'description' => "1) Включите отладку по USB / сетевую отладку на ТВ или приставке (Настройки → О устройстве → 7× по номеру сборки → Для разработчиков → USB debugging / Network debugging).\n\n2) Узнайте IP приставки в той же LAN, что ПК с ADB.\n\n3) С ПК (platform-tools):\n   adb connect IP:5555\n   adb devices\n   adb install -r путь\\к\\app-debug.apk\n\nDebug-сборка обычно:\n   C:\\Qt\\shell_apk\\app\\build\\outputs\\apk\\debug\\app-debug.apk\n\n4) Запуск:\n   adb shell am start -n ru.compclub.tvshell/.ui.LoginActivity\n\n5) Первичный Setup в приложении: 5× тап по заголовку входа → PIN 0451 → URL сервера (https://0451.space) → имя станции → ПРОВЕРИТЬ HWID → ЗАРЕГИСТРИРОВАТЬ TV. При необходимости — привязка вентиляторов (ТЕСТ → ПРИВЯЗАТЬ).\n\nСборка APK (JDK 17):\n   cd C:\\Qt\\shell_apk\n   set JAVA_HOME=C:\\Qt\\jdk-17\n   gradlew.bat assembleDebug",
                        'path' => null,
                        'audience' => 'Техник',
                    ],
                    [
                        'title' => 'Киоск: Home и Device Owner',
                        'description' => "Рекомендуется для клубных панелей:\n\n# Сделать шелл домашним лаунчером\nadb shell cmd package set-home-activity ru.compclub.tvshell/.ui.LoginActivity\n\n# Device Owner (только чистое устройство без аккаунтов Google / после factory reset):\nadb shell dpm set-device-owner ru.compclub.tvshell/.kiosk.ShellDeviceAdminReceiver\n\nС Device Owner: Lock Task режет Home/Recent жёстче; в whitelist сессии добавляются разрешённые приложения (YouTube и т.д.).\nБез DO киоск слабее: Home может открыть стоковый лаунчер — KioskGuard пытается вернуть шелл на idle.",
                        'path' => null,
                        'audience' => 'Техник',
                    ],
                    [
                        'title' => 'Железо ТВ: CEC, HDMI и киоск',
                        'description' => "Шелл не управляет физическим селектором входов ТВ и HDMI-CEC. Стабильность на клубе = настройки железа + Device Owner/Home, а не только APK.\n\n1) HDMI-CEC / Auto Input Switch (обязательно на панелях у PS5/приставок):\nПри нажатии PS на геймпаде консоль шлёт CEC Active Source — многие ТВ сами переключают вход на HDMI приставки и «съедают» Android-слой. В настройках ТВ выключите:\n• HDMI-CEC / Anynet+ / Bravia Sync / Simplink / EasyLink (или отдельно «Auto Input Switch» / «автопереключение входа»)\n• при необходимости оставьте CEC только для пульта/питания, без auto-switch\nADB (если пункт спрятан; имена зависят от OEM):\n  adb shell settings put global hdmi_control_auto_device_off_enabled 0\n  adb shell settings put global hdmi_control_enabled 0\nПроверьте на конкретной модели — ключи не универсальны.\n\n2) HDMI passthrough / PiP (TCL, Xiaomi, Hisense и др.):\nКнопка HDMI в сессии работает только если TvInputManager отдаёт passthrough. На части прошивок переход на HDMI уводит Android в PiP/фон или рвёт лаунчер. Это ограничение OEM: шелл не эмулирует релейный HDMI-switcher. Если passthrough нестабилен — не выставляйте HDMI как основной сценарий; используйте отдельный монитор/матрицу или внешний свитчер.\nВ сессии KioskGuard выключен специально (YouTube/HDMI разрешены) — CEC/PiP в этот момент кодом не отбиваются.\n\n3) Чеклист установки панели:\n• factory reset → без Google-аккаунта → install APK → set-home-activity → Device Owner\n• выключить CEC Auto Input Switch\n• отключить автообновления прошивки/магазинов, которые сбрасывают home\n• проверить: idle (логин держится), Home не уходит в сток, сессия → YouTube и назад, PS5 не перехватывает вход без разрешения\n• сеть: MAC ТВ в MikroTik isolate на idle / restore на сессии\n\n4) Что делает код:\nKioskGuard на idle: если шелл ушёл в фон — moveToFront + startActivity (SINGLE_TOP) ~каждые 800 мс, пока снова не на переднем плане. Это не shell «am start» и не замена Device Owner/CEC-настроек. На сессии guard выключен.",
                        'path' => null,
                        'audience' => 'Техник / Supervisor+',
                    ],
                    [
                        'title' => 'Удаление APK',
                        'description' => "Обычное удаление:\n  adb uninstall ru.compclub.tvshell\n\nЕсли был home-лаунчер — верните стоковый (имя пакета зависит от бренда), например:\n  adb shell cmd package set-home-activity com.google.android.tvlauncher/.MainActivity\n\nЕсли был Device Owner, uninstall может отказать. Сначала:\n  adb shell dpm remove-active-admin ru.compclub.tvshell/.kiosk.ShellDeviceAdminReceiver\nили\n  adb shell dpm clear-device-owner\n(иногда только factory reset). Затем снова adb uninstall.\n\nЧерез UI: Настройки → Приложения → CompClub TV → Удалить (если не Device Owner).",
                        'path' => null,
                        'audience' => 'Техник',
                    ],
                    [
                        'title' => 'Idle (гость / нет сессии)',
                        'description' => "Экран LoginActivity: телефон + PIN, экранная цифровая клавиатура (системная IME выключена).\n\nОверлеи: 6 слотов left/right (top/mid/bottom) из GET /api/shell/overlays. Слои image / video / text. Видео — ExoPlayer + SurfaceView, старт со сдвигом 0…1250 мс, mute, loop. На onPause / уходе с idle — полный release плееров (не просто hide).\n\nСеть: SessionNetworkPolicy → ui-state session_idle → очередь isolate на MikroTik (MAC с терминала).",
                        'path' => '/admin/overlays',
                        'audience' => 'TV / Система',
                    ],
                    [
                        'title' => 'Авторизация и активная сессия',
                        'description' => "POST /api/shell/login (phone, pin, terminal_id) → активация брони (BookingSessionTimingService), баланс, time_remaining.\n\nОверлеи убиваются (kill/release). ui-state session_active → MikroTik restore (интернет открыт).\n\nSessionActivity: имя, баланс, таймер (локальный tick + poll balance/heartbeat ~8 с), предупреждения 10/5/1 мин.\nKioskGuard выключен — можно уходить в YouTube/HDMI.\nЛаунчер: LEANBACK-приложения (YouTube/Кинопоиск в приоритете), Settings/Play скрыты.\nHDMI-кнопки — только если TvInputManager отдаёт passthrough (иначе блок скрыт).\nSOS → POST /api/shell/sos.\nПродлить → billing/topup confirmation=redirect → QR на телефоне.\nLAN CommandService :8787 (message, session_end, …).",
                        'path' => null,
                        'audience' => 'TV / Игрок',
                    ],
                    [
                        'title' => 'Конец сессии на TV',
                        'description' => "Триггеры: кнопка «Завершить», таймер 0, heartbeat/balance session_active=false, LAN session_end, истечение на сервере (completeExpiredSessions + isolate для kind=tv).\n\nДействия: logout API → очистка SessionStore → session_idle (isolate) → KioskGuard снова включён → Login + оверлеи.\nLock Task whitelist снова только пакет шелла.",
                        'path' => null,
                        'audience' => 'TV / Система',
                    ],
                    [
                        'title' => 'Время сессии: кабинет vs шелл',
                        'description' => "Кабинет игрока считает remaining по wall-clock: date + start_time + duration.\nШелл раньше мог брать «кривой» ends_at (+~3 ч из-за naive timestamp / timezone).\n\nСейчас Shell API (login + /api/shell/balance) использует ту же логику, что кабинет, и при перекосе чинит starts_at/ends_at (healSkewedWindow). После деплоя таймер на TV должен совпадать с кабинетом (~1 ч бронь → ~1 ч на экране).",
                        'path' => '/account/dashboard',
                        'audience' => 'Система / TV',
                    ],
                    [
                        'title' => 'Эмулятор Android TV',
                        'description' => "AVD Google TV удобнее запускать из CLI (Device Manager часто зависает на Starting up со скрытым окном):\n\n  C:\\Android\\emulator\\emulator.exe -avd Television_1080p -gpu host -no-snapshot-load\n\nЕсли чёрный экран — попробуйте -gpu swiftshader_indirect.\nЗатем в Studio Run на уже живой emulator-5554.\nAPI 34 стабильнее сырого API 36; RAM AVD лучше 3–4 ГБ.\nКириллица в пути профиля Windows (C:\\Users\\Админ) иногда ломает эмулятор — AVD на C:\\Android\\avd помогает.",
                        'path' => null,
                        'audience' => 'Техник',
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
                        'description' => 'Баланс, активные брони с таймером, заказы магазина, транзакции, прогресс достижений, статус заявки на бонус за отзыв.\n\nКнопка «Сесть за ПК» без живой сессии — заготовка быстрого входа (логика «Подключиться» ещё заглушка). При активной сессии кнопка становится «Пересесть» (см. «Пересадка на другой ПК»).',
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
                        'description' => "Витрина товаров. Заказ только при активной сессии за ПК; списание с депозита.\n\nДоставка всегда к ПК, где сейчас открыта сессия (pc_name / terminal активной брони) — и с Shell, и с сайта/ЛК. Не к месту исходной брони «на бумаге», если гость уже сидит за другим терминалом после пересадки (когда пересадка будет реализована — заказы следуют за актуальным computer_id).",
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
                        'description' => 'Привязка ПК по HWID: check и register-terminal. Для TV используйте zone_type=tv (kind=tv) — см. раздел «TV Shell».',
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Киоск Windows (SecurityManager)',
                        'description' => "PC Shell: src/core/securitymanager.cpp — локдаун гостевой сессии Windows (реестр HKCU Policies).\n\nЧто делает lockDownSystem(): DisableCMD / DisableRegistryTools / DisableTaskMgr / DisableChangePassword / DisableLockWorkstation; Explorer: NoWindowsKey, NoRun, NoDrives, NoFind, NoViewContextMenu; StickyKeys Flags=506; Shell=путь к REACTOR вместо explorer.exe. unlockSystem() откатывает (обслуживание образа).\n\nСейчас выключено: в main.cpp флаг isProduction=false — SecurityManager не вызывается (режим DEVELOPMENT). Для клуба на бездиске: собрать образ → включить isProduction=true (или вынести в конфиг) → проверить выход админа/обновление образа через unlock. Без этого гость может уйти в TaskMgr/Win+R/проводник.\n\nНе путать с TV KioskGuard / Device Owner и с MikroTik isolate сети.",
                        'path' => null,
                        'audience' => 'Shell / Техник',
                    ],
                    [
                        'title' => 'Логин сессии',
                        'description' => 'Телефон + PIN брони + terminal_id → активация сессии, остаток времени, баланс и settings_pack (Cloud Saves) для накатки конфигов на этот ПК.',
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Баланс и poll',
                        'description' => 'Периодический опрос баланса. При опросе закрываются просроченные сессии; remaining считается согласованно с кабинетом (wall-clock / heal ends_at).',
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
                    [
                        'title' => 'Питание и WOL на PC Shell',
                        'description' => "POST /api/shell/power/heartbeat (~30 с) + MAC NIC → online / очередь WOL.\nPOST /api/shell/power/offline при штатном уходе.\nВ ответах logout/balance/poll может прийти power_action=reboot|shutdown по desired питания (бронь ± warmup).\nMagic packet шлёт MikroTik из /api/power/wol-targets, не шелл и не облако напрямую. Настройка токена и warmup — .env CLUB_*; статусы на дашборде. Подробности — «Питание ПК» в Конфигурации.",
                        'path' => '/admin/dashboard',
                        'audience' => 'Shell / MikroTik',
                    ],
                    [
                        'title' => 'Климат на PC Shell',
                        'description' => 'Плитка климата: режимы auto / 50% / 75% / 100%. Сервер отдаёт desired; Shell пульсирует W5100 по LAN и шлёт fan/applied. Подробности железа — в «Вентиляция» (Конфигурация клуба).',
                        'path' => '/admin/fans',
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Голосовой ИИ (F1 и приветствие)',
                        'description' => "F1 во время сессии: запись с микрофона → POST /api/shell/ai-assistant → Whisper STT → LLM (DeepSeek/OpenAI из админки) → OpenAI TTS в наушники. Нужна активная бронь на ПК.\n\nПосле логина: POST /api/shell/voice-greeting — короткое персональное приветствие в колонки лобби (имя, первый визит / любимые игры). Промпты, голос и ключи — /admin/ai-assistant; Shell только шлёт аудио/terminal_id и воспроизводит ответ.",
                        'path' => '/admin/ai-assistant',
                        'audience' => 'Shell / Supervisor+',
                    ],
                ],
            ],
            [
                'id' => 'sessions',
                'title' => 'Сессии',
                'items' => [
                    [
                        'title' => 'Активация и тайминг',
                        'description' => 'Ранний старт сдвигает ends_at с сохранением оплаченной длительности (duration — источник истины при timezone-skew). Опоздание: если нет следующей брони на ПК — до 30 мин ожидания без списания, затем списание; при следующей брони списание с starts_at. Grace не блокирует слот после ends_at. No-show — когда эффективное время истекло без входа. Самоотмена гостем с возвратом — до дедлайна из /admin/booking-settings (по умолчанию за 2 ч до starts_at); позже отмена недоступна, оплата удерживается.',
                        'path' => null,
                        'audience' => 'Система / Shell',
                    ],
                    [
                        'title' => 'Пересадка на другой ПК',
                        'description' => "Статус: реализовано (самообслуживание).\n\nAPI Shell: GET /api/shell/transfer/targets, POST /api/shell/transfer/preview|confirm (terminal_id + target_computer_id) — список свободных ПК. ЛК: GET /account/transfer/targets отдаёт targets + map_config/computers/occupied_ids/selectable_ids; модалка «Пересесть» показывает ClubMap. Shell UI: «ПЕРЕСЕСТЬ» (список, без SVG-карты).\n\nПравила:\n• только status=active, целевой ПК свободен до ends_at, тот же клуб, kind=pc;\n• дороже: доплата с баланса с сохранением времени; если денег мало — укоротить ends_at (prepaid value + баланс / новый ₽/ч);\n• дешевле: без возврата, время не растёт;\n• бронь не complete: меняются computer_id/pc_ids; старый Shell получает session_active=false на balance-poll (soft-kick, без logout-complete);\n• вход на новом ПК — новый PIN выдаётся при пересадке (старый после старта сессии уже сжигался).\n\nЗаказы бара: pc_name = ПК активной сессии на момент заказа (см. «Магазин»).\n\nНе путать с «Сесть за ПК» без живой сессии (заготовка входа) и с gift-причиной «Пересадка по вине клуба».",
                        'path' => '/account/dashboard',
                        'audience' => 'Игрок / Shell',
                    ],
                    [
                        'title' => 'Автозакрытие сессий',
                        'description' => 'Команда reactor:update-statuses каждую минуту: no-show (неначатые с истекшим эффективным временем + settle чека), закрытие активных сессий по ends_at, дозакрытие зависших deferred-чеков, busy/available ПК, пересчёт питания ПК (desired/WOL-state). Для kind=tv — isolate в очередь MikroTik.',
                        'path' => null,
                        'audience' => 'Система',
                    ],
                    [
                        'title' => 'Контроль качества заказов',
                        'description' => "reactor:check-quality каждую минуту: инцидент late_order, если заказ висит pending дольше 5 минут; инцидент low_stock, если у товара задан min_stock и stock ≤ порога (без дублей, пока инцидент не закрыт).\n\nПрофилактика задержки: звук + тост на /admin/orders при новом заказе (см. «Очередь заказов») — late_order остаётся страховкой, если очередь не смотрели.",
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
