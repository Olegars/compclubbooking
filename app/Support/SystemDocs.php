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
                        'description' => 'Карта и статусы ПК, сводка по выручке магазина, активным сессиям и новым гостям. Поиск игрока по телефону, пополнение депозита с кассы, выдача бонусного времени. Владелец: клик по плитке ПК → «Освободить компьютер» закрывает залипшую сессию, если шелл убили без logout.',
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
                        'title' => 'Склад бара',
                        'description' => 'Склад бара/кухни (/admin/inventory) — не путать со складом комплектующих магазина ПК (/admin/store/warehouse).\n\nКаталог, приёмка сканом, списание и угощение с причиной (просрочка / бой / комп) — пишется в journal stock_movements, чтобы не всплывало как «кража» на пересменке. КМ — по DataMatrix; немеченое — количеством.\n\nСебестоимость: при приёмке указывается закупочная цена (unit_cost) и поставщик → создаётся партия inventory_batches (FIFO), пересчитывается средневзвешенная cost_price на карточке, при поставщике и цене > 0 — открытый счёт в долгах. При продаже/списании себестоимость (COGS) списывается FIFO и пишется в meta движения.\n\nМин. остаток (min_stock): если задан и stock ≤ порога, reactor:check-quality создаёт инцидент low_stock (без дублей, пока не закрыт).',
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
                'id' => 'pc-store',
                'title' => 'Магазин компьютеров (REACTOR Store)',
                'items' => [
                    [
                        'title' => 'Обзор модуля',
                        'description' => "Отдельный контур сборки и продажи ПК (не путать со складом бара /admin/inventory).\n\nПоток: смета клиенту → позиции из каталога ITP/QuickFox и/или со склада → заказ недостающего у поставщика → приёмка на склад с серийниками → сборка ПК → заказ магазина / выдача → гарантия (QR, талон) → при обращении: ремонт / возврат в сборку / замена детали.\n\nРазделы: /admin/store/estimates, /warehouse, /built-pcs, /orders, /warranty, /clients. Локации (клубы) — /admin/store/locations, переключение локации влияет на выборку склада/смет.",
                        'path' => '/admin/store/estimates',
                        'audience' => 'Админ / Сборщик / Owner',
                    ],
                    [
                        'title' => 'Сметы',
                        'description' => "Смета = комплектация ПК для клиента. Статусы: draft → agreed → procuring → ready → converted / cancelled.\n\nПозиции набираются из каталога поставщика (пикер: поиск, чипы фильтров, картинки с лайтбоксом) или со склада. В шапке формы — живые итоги продажи и закупки.\n\nPDF: кнопка «PDF» в карточке/форме → /admin/store/estimates/{id}/pdf (A4, продажные цены; в диалоге печати — «Сохранить как PDF»).\n\n«Цены API» — обновить цены/остатки по sku сметы. «Заказать недостающее» — заказ в QuickFox (EXT), запись store_purchases. «Принять на склад» — попап: серийник и комментарий по каждой позиции → комплектующие в статусе reserved под смету. «В заказ магазина» — convert: продажа со склада, статус sold.\n\nПозиции: planned / from_stock / to_order / ordered / received.",
                        'path' => '/admin/store/estimates',
                        'audience' => 'Админ',
                    ],
                    [
                        'title' => 'Каталог поставщика (QuickFox / ITP)',
                        'description' => "Локальный кэш store_supplier_catalog_* из B2B ITP (QuickFox API).\n\nСинк: artisan store:sync-supplier-catalog (по cron schedule в 09:00 Europe/Moscow). Upsert по sku: не затирает DeepSeek-разметку корпусов (case_*) и кэш картинок; при смене name/part разметка корпусов сбрасывается. Позиции, исчезнувшие из прайса, удаляются. Цены/остатки — get_active_products (лимит API ~10/час): «в наличии» только с ценой; при синке каталога цены сначала обнуляются, затем выставляются активные.\n\nКартинки: products_clients_images → прокси /admin/store/estimates/catalog-image/{sku}. Корпуса: store:classify-cases (09:40) + DeepSeek (DEEPSEEK_API_KEY) — цвет / стекло / ATX.\n\nEnv: STORE_QUICKFOX_DOMAIN (без /api/2), LOGIN, PASSWORD, опционально CATEGORY_IDS, пути CATALOG_TREE / PRODUCTS. Гарантия из прайса: warranty («0» = 12 мес.) → warranty_months при приёмке на склад.\n\nОтмены заказа у поставщика через API нет — только правка строк order_items.",
                        'path' => '/admin/store/estimates',
                        'audience' => 'Админ / Система',
                    ],
                    [
                        'title' => 'Склад комплектующих',
                        'description' => "Учёт штучных комплектующих (store_components): тип, конструктор названия + specs, серийники, закупка, поставщик, гарантия (мес.), статусы in_stock / reserved / used / sold / repair / written_off.\n\nВ таблице нет количества (всегда 1) и «оригинала» (original_name хранится, заполняется сверкой сборки). Есть дата поступления. Клик по строке — карточка: EXT заказа поставщика и sku (если пришло из закупки), остаток гарантии от даты поступления, продажа (клиент, кто продал, сборка, дата).\n\nПроданные нельзя edit/del. Приход вручную или сканером серийника; приёмка из сметы пишет EXT/sku в связь purchase_item.\n\nРемонт: статус repair, строка «передана в ремонт дата»; связь со сборкой сохраняется.",
                        'path' => '/admin/store/warehouse',
                        'audience' => 'Админ / Сборщик',
                    ],
                    [
                        'title' => 'Сборки ПК',
                        'description' => "store_built_pcs: комплектация из складских позиций (статус used), клиент, сборщик, серийник сборки (10 цифр), продажа. Печать QR/талона гарантии с карточки сборки.\n\nСверка сборки: POST /api/build-verify (токен STORE_BUILD_VERIFY_TOKEN) — сопоставление серийников с ожидаемой комплектацией, original_name, замена деталей.",
                        'path' => '/admin/store/built-pcs',
                        'audience' => 'Админ / Сборщик',
                    ],
                    [
                        'title' => 'Заказы магазина',
                        'description' => "Заказы продажи ПК/комплектующих клиенту магазина (store_orders): статусы new → assembling → ready → issued / cancelled / returned. Назначение сборщика, позиции со склада. При выдаче (issued) сборка и комплектующие уходят в sold; создаётся/обновляется гарантия.",
                        'path' => '/admin/store/orders',
                        'audience' => 'Админ / Сборщик',
                    ],
                    [
                        'title' => 'Гарантии',
                        'description' => "store_warranties: срок STORE_WARRANTY_MONTHS (по умолчанию 12), ремонт STORE_REPAIR_DAYS (45). Статусы active / claimed / closed. Снимок комплектации build_snapshot.\n\nВ списке — остаток гарантии сборки; кнопка Active красная, если в сборке есть деталь в repair. Клик — попап: комплектующие, у каждой остаток гарантии (дни от поступления + warranty_months), кнопки «В ремонт» / «Вернуть в сборку» / «Списать со склада» (замена: новая деталь с пометкой «замена ID…», старая written_off, в сборке подмена).\n\nПечать: QR (HTML 80 мм / POS-очередь), гарантийный талон A4. Претензии в API ITP нет — оформление у поставщика вручную (warranty@ / claims@ / ЛК B2B).",
                        'path' => '/admin/store/warranty',
                        'audience' => 'Админ / Сборщик',
                    ],
                    [
                        'title' => 'Клиенты магазина',
                        'description' => 'Отдельная база клиентов сборок/гарантий (store_clients): имя, телефон. Привязка к сметам, заказам, сборкам и гарантиям.',
                        'path' => '/admin/store/clients',
                        'audience' => 'Админ',
                    ],
                    [
                        'title' => 'Планировщик синка каталога',
                        'description' => "Laravel Schedule сам не крутится: на сервере cron пользователя www-data (тот же, что PHP-FPM):\n* * * * * cd /var/www/… && /usr/bin/php artisan schedule:run >> /dev/null 2>&1\n\nЗадачи: store:sync-supplier-catalog в 09:00 Europe/Moscow; store:classify-cases в 09:40. Логи (если включены в routes/console.php): storage/logs/catalog-sync.log, catalog-cases.log.\nПроверка: sudo -u www-data php artisan schedule:list / schedule:run -v. Разовый синк: store:sync-supplier-catalog.",
                        'path' => null,
                        'audience' => 'Система / Owner',
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
                        'description' => "Уже есть (без IPMI / Smart PDU):\n\n• ComputerPowerService: desired on/off по активным и ближайшим броням (warmup CLUB_POWER_WARMUP_MINUTES, по умолчанию 30 мин до старта). Техрежим (computers.maintenance / status=maintenance) держит desired=on и power_action=none — idle не гасит место.\n• Онлайн = свежий heartbeat шелла (last_seen_at; stale CLUB_POWER_HEARTBEAT_STALE_SECONDS).\n• Состояния: on / off / booting / error (таймаут WOL или нет MAC). Плитки «сервис» и «кэш» — техрежим и cache_ok=false.\n• Wake-on-LAN: облако само magic packet в LAN не шлёт. MikroTik (или LAN-агент) pull’ит очередь GET /api/power/wol-targets?token=… и подтверждает POST /api/power/wol-sent (токен CLUB_WOL_RELAY_TOKEN). MAC приходит с шелла в /api/shell/power/heartbeat.\n• После logout / idle при desired=off сервер отдаёт power_action=shutdown|reboot; Shell (Qt) делает S5 (shutdown /s или /r, не Sleep) с flush SSD.\n• Heartbeat дополнительно: cache_ok, cache_free_gb, data_root, volume_letter, maintenance.\n• Дашборд: снимок питания ПК, в т.ч. «Ошибка WOL», «кэш SSD мёртв», «обслуживание».\n• Cron reactor:update-statuses пересчитывает desired/state вместе со статусами сессий.\n\nНет: IPMI, Smart PDU, кнопка «принудительно выключить/перезагрузить» из админки поверх расписания, интеграция с diskless-бутлоадерами.",
                        'path' => '/admin/dashboard',
                        'audience' => 'Supervisor+ / Shell / MikroTik',
                    ],
                    [
                        'title' => 'Diskless / кэш игр — статус',
                        'description' => "Не реализовано в booking: управление CCBoot/SENET/NDEV, смена образа кнопкой в админке, Steam-кэш как сервис.\n\nПравка золотого образа — с игрового ПК: Shell setup (Win+ПКМ) → Super Client. Пошагово: «Обслуживание образа: setup и Super Client» в разделе Shell.\n\nСеть под бездиск — «Сеть клуба»: VLAN 20 общий L2 сервер+ПК, 2×10G LACP на CRS354, DHCP только один источник. Админка оркестрирует лицензии/WOL, не PXE.",
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
                        'description' => 'См. раздел «Сеть клуба (бездиск, ПК, камеры)»: закладки HID/SOS на таймлайне NVR через LAN-агент.',
                        'path' => '/admin/video-surveillance',
                        'audience' => 'Supervisor+ / Shell / LAN-агент',
                    ],
                    [
                        'title' => 'ИИ-ассистент',
                        'description' => "Голосовой компаньон Shell (Grave/Ё) и персональное приветствие при логине. Настройки на клуб: вкл/выкл, LLM (DeepSeek или OpenAI), речь — Yandex SpeechKit (по умолчанию, из РФ) или OpenAI Whisper+TTS. Ключи LLM / SpeechKit / OpenAI, Folder ID, голос TTS (Алёна/Филипп/… или nova/alloy), макс. длина ответа, системные промпты F1 и приветствия с плейсхолдерами {{club}}, {{player}}, {{game}}, {{pc}}, {{time}}, {{visit_line}}, {{games}}, {{max_chars}}.\n\nКлючи в админке шифруются в БД; пустое поле при сохранении не затирает; «очистить» возвращает fallback на .env. Живой выключатель — тумблер в админке. Без ключей (ни БД, ни .env) пайплайн не стартует.\n\nShell: POST /api/shell/ai-assistant (аудио → STT → LLM → TTS), POST /api/shell/voice-greeting (контекст игрока → LLM → TTS в колонки лобби).",
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
                'id' => 'network',
                'title' => 'Сеть клуба (бездиск, ПК, камеры)',
                'items' => [
                    [
                        'title' => 'Сборка сети: порядок и железо',
                        'description' => "По этой главе собирается LAN клуба с нуля. Booking в облаке в розетки не ходит — только HTTPS с площадки наружу.\n\nЖелезо:\n• Роутер MikroTik RB5009UG+S+IN (7×1G + 1×2.5G + 1×10G SFP+) — WAN, NAT, VLAN, hotspot, WOL, isolate. Не коммутатор зала, без контейнеров.\n• Свитч MikroTik CRS354-48G-4S+2Q+RM (48×1G + 4×10G SFP+ + 2×40G QSFP+) — только switching. NAT/DHCP на CRS не включать.\n• Бездиск: сервер + NIC LR-LINK LREC9812AF-2SFP+ (2×10G SFP+). В стойке DAC 10G SFP+, не оптика.\n• 5× Dahua DH-CS4010-8ET2GT-110 (8×100M PoE + 2×1G uplink, 110 Вт) — камеры.\n• NVR Hikvision DS-7764NI-M4; до 40× HiWatch DS-I402(D) 4 Мп 2.8 мм.\n• Игровые ПК (до ~40 на одном CRS354, гигабит в медь); ПК лиц; касса; ТВ/PS; принтер кухни.\n\nПорядок включения: 1) стойка и кабель 2) VLAN/IP 3) CRS354 4) RB5009 5) бездиск 6) игровые ПК 7) камеры/NVR 8) агенты (WOL, кухня, метки). Не включать бездиск, пока LACP 10G не линкуется.",
                        'path' => null,
                        'audience' => 'Техник',
                    ],
                    [
                        'title' => 'Адреса и VLAN (шаблон)',
                        'description' => "Три сети. Цифры можно сдвинуть, смысл оставить.\n\nVLAN 20 «зал» 192.168.20.0/24 — бездисковый сервер и ВСЕ игровые ПК в одном L2 (иначе PXE/iSCSI не взлетит). Шлюз .1 = RB5009. Сервер бездиска .10. ПК .100–.199 (DHCP или резерв по MAC). Касса/кухня/ПК лиц тоже здесь, им нужен интернет к booking.\n\nVLAN 30 «камеры» 192.168.222.0/24 — как уже стоит NVR (.12). Шлюз .1 = RB5009 только для NTP/админки с ПК лиц, без NAT в интернет. Камеры .20–.59. PoE-свитчи управления .2–.6.\n\nVLAN 10 «mgmt» 192.168.10.0/24 — Winbox CRS/RB, IPMI/iLO если появится. Не светить в зал.\n\nVLAN 40 hotspot — гости, walled garden (см. «Гостевой Wi-Fi»). Не в VLAN 20.\n\nFirewall на RB5009: VLAN 20 → WAN (игры). VLAN 30 ↛ WAN. С зала на NVR :80/:8000 только с ПК лиц (или mgmt), не с игровых. Зал ↛ камеры. Камеры ↛ зал. WOL — directed broadcast в VLAN 20.\n\nRB5009: ether1 провайдер, ether2 LTE failover, sfp-sfpplus1 trunk на CRS354 (tagged 10/20/30/40).",
                        'path' => null,
                        'audience' => 'Техник',
                    ],
                    [
                        'title' => 'Кабель и порты CRS354',
                        'description' => "Стойка: RB5009 + CRS354 + бездиск рядом. Питание UPS на роутер, свитч, бездиск, NVR.\n\nCRS354 SFP+:\n• sfp-sfpplus1 + 2 — DAC на два порта LREC9812AF, bonding 802.3ad (LACP), VLAN 20. Цель ≈ 20 Гбит на образы.\n• sfp-sfpplus3 — DAC/оптика 10G на RB5009, trunk.\n• sfp-sfpplus4 — запас (второй зал / второй CRS в 10G). QSFP+ 40G — стек, пока не нужен.\n\nМедь CRS354 (пример, подписать порт):\n• ether1–40 — игровые ПК, access VLAN 20, 1 Гбит. Один ПК = один порт, без домашней гирлянды.\n• ether41–45 — uplink с пяти Dahua (порт 9 каждого CS4010), access VLAN 30.\n• ether46 — NVR, access VLAN 30, IP 192.168.222.12.\n• ether47 — ПК лиц, access VLAN 20; на RB5009 разрешить этому MAC/IP ходить на 192.168.222.12:80.\n• ether48 — касса / ноут техника, VLAN 20 или 10.\n\nПроверка меди: линк 1G full duplex, не 100M (плохая витая/патч). Камеры на 100M — норма, это порты Dahua.",
                        'path' => null,
                        'audience' => 'Техник',
                    ],
                    [
                        'title' => 'Бездисковый сервер',
                        'description' => "Booking не управляет CCBoot/SENET/NDEV — только сеть под него. Сервер и клиенты обязаны быть в VLAN 20 без маршрутизатора между ними.\n\n1) Поставить LREC9812AF-2SFP+ в сервер, драйвер как Intel X520, отключить энергосбережение NIC.\n2) В Windows NIC teaming / LACP на два SFP+; на CRS354 — тот же LACP. Пока bond не Up/Up — клиенты не включать.\n3) Адрес сервера 192.168.20.10/24, шлюз 192.168.20.1, DNS — роутер или публичный. Отдельная медь «на всякий» в VLAN 20 допустима как аварийный доступ, не как путь образов.\n4) DHCP для PXE: либо сам бездиск (тогда на RB5009 DHCP VLAN 20 выкл или exclude диапазон PXE), либо DHCP на MikroTik с option 66/67 на сервер — один источник DHCP, два не запускать.\n5) Образы/игры на локальных дисках сервера. 20 Гбит хватает на ~30–40 одновременных загрузок; стриминг Steam с сервера не мешать с PXE в час пик без кэша.\n6) На этот сервер не ставить агент меток, кухню, антивирус-сканер дисков клуба в рабочее время. Упал бездиск — встал весь зал.\n7) Правка образа — Super Client с одного игрового ПК (Shell setup), не с этого сервера «руками в VHD пока зал работает». Шаги — «Обслуживание образа: setup и Super Client».",
                        'path' => '/admin/licenses',
                        'audience' => 'Техник',
                    ],
                    [
                        'title' => 'Игровые компьютеры',
                        'description' => "Каждый ПК: гигабит в ether1–40 CRS354, VLAN 20, PXE в BIOS/UEFI (LAN IPv4). WOL в BIOS включить; Shell шлёт MAC в /api/shell/power/heartbeat, magic packet шлёт MikroTik из /api/power/wol-targets (тот же VLAN 20).\n\nСеть клиента: DHCP из выбранного на шаге бездиска источника. Шлюз 192.168.20.1 — интернет игр и HTTPS к booking. Доступа на 192.168.222.0/24 у гостевого образа быть не должно.\n\nПосле образа: зарегистрировать терминал Shell (HWID), имя = место (PC-08). Isolate/hotspot — не путать с портом камеры.\n\nОбновление образа (Windows, Shell, драйверы кроме NIC): не копировать папку с ПК на ПК. Один клиент → Super Client с setup шелла (Win+ПКМ). Глава «Обслуживание образа: setup и Super Client». На клиенте должен лежать CCBootClient.exe. iCafeMenu не ставить.\n\nТВ/PS (kind=tv): тоже VLAN 20 (или отдельный access), idle → MikroTik isolate MAC, сессия → restore. Не сажать ТВ в VLAN камер.\n\nКасса, кухня (ESC/POS :9100), W5100 вентиляции — VLAN 20 служебные адреса, не DHCP игроков. Кухонный агент scripts/kitchen-print-agent.ps1 на кассе/NUC, не на бездиске.\n\nБольше 40 мест: второй CRS (или 24-порт) в 10G на sfp-sfpplus4, тот же VLAN 20. Не вешать игроков на RB5009 copper «временно».",
                        'path' => '/admin/dashboard',
                        'audience' => 'Техник / Shell',
                    ],
                    [
                        'title' => 'RB5009: что настроить на роутере',
                        'description' => "Режим: роутер, не bridge-на-все-порты. WAN ether1 + ether2 failover (см. dual-WAN в health-check). Bridge только если нужен; зал и камеры — VLAN-интерфейсы на trunk sfp-sfpplus1.\n\nDHCP: VLAN 20 (ПК) и VLAN 40 (hotspot). VLAN 30 — лучше статика/DHCP на NVR, не раздавать интернет камерам.\n\nNAT masquerade только с VLAN 20 и 40 на WAN. FastTrack для игр ок, не ломать raw/mangle isolate если уже есть.\n\nСкрипты (уже в продукте, не сеть с нуля): poll GET /api/power/wol-targets и /api/power/isolate-targets, Wi-Fi grant-targets. Токен CLUB_WOL_RELAY_TOKEN. Scheduler 2–5 с.\n\nWinbox только с VLAN 10/20 mgmt, не с гостевого Wi-Fi. Обновления RouterOS — планово, не в час пик зала.\n\nНе делать: DST-NAT 80/443/8000 на NVR; контейнер с агентом меток; DHCP VLAN 20 параллельно CCBoot.",
                        'path' => null,
                        'audience' => 'Техник / MikroTik',
                    ],
                    [
                        'title' => 'Камеры: 5× Dahua PoE + HiWatch + NVR',
                        'description' => "Регистратор DS-7764NI-M4 на ether46, IP 192.168.222.12, маска /24, шлюз .1 (NTP). Конфиг системы → Сеть → ISAPI вкл; HTTP(S) :80 Digest. Запись на HDD NVR, не на бездиск.\n\nКамеры HiWatch DS-I402(D) 4 Мп 2.8 мм (~99°), H.265+, PoE ~6.5 Вт, порт 100 Мбит, IP67. Родные для Hikvision. Лиц в камере нет — субпоток на ПК лиц позже; основной 4 Мп в NVR. Битрейт 2–4 Мбит, не 8. 40 шт. ≈ 80–160 Мбит при лимите NVR ~400.\n\n5× CS4010: по зонам (вход, зал1, зал2, бар, улица). Порт 9 каждого → ether41–45 CRS354, VLAN 30. Не гирлянда Dahua→Dahua. 8×6.5 Вт ≈ 52 из 110 Вт. PTZ только порты 1–2. Extend 250 м выкл. DoLynk выкл.\n\nДобавление: NVR «Доступ к устройству» / Plug and Play в VLAN 30, протокол Hikvision. Пароли камер не дефолт admin/12345.\n\nНа 64 канала — ещё PoE-свитчи в те же ether/второй CRS, ядро не менять.",
                        'path' => null,
                        'audience' => 'Техник',
                    ],
                    [
                        'title' => 'Видео-метки: зачем и куда смотреть',
                        'description' => "Закладка на записи NVR, чтобы не мотать 40 каналов: HID (мышь/клава) или SOS → флажок с текстом «HID · PC-08». Это не иконка лица/машины и не «Событие AIOP».\n\nИскать: Воспроизведение → камера (канал из админки, 1 = D1) → шкала; либо бэкап/поиск по тегу.\n\nТриггеры: hid.disconnected / hid.device_changed / hid.unstable (POST /api/shell/hid/alert), sos (POST /api/shell/sos), тест в админке. События — /admin/video-surveillance. Канал = номер камеры NVR (1 → track 101). ПК→камера пока нет.",
                        'path' => '/admin/video-surveillance',
                        'audience' => 'Supervisor+ / Админ / Техник',
                    ],
                    [
                        'title' => 'Видео-метки: агент на ПК лиц',
                        'description' => "Облако на NVR не ходит. Очередь video_surveillance_marker_jobs → агент scripts/hikvision-marker-agent.ps1 на ПК лиц (VLAN 20 + доступ к 192.168.222.12). Не бездиск, не RB5009.\n\nАгент: GET /api/video/marker-targets?token=… → Digest PUT …/recordTag + lock → POST /api/video/marker-applied. Env: VIDEO_API_BASE, VIDEO_MARKER_TOKEN (= VIDEO_MARKER_RELAY_TOKEN или CLUB_WOL_RELAY_TOKEN). Планировщик Windows, автозагрузка.\n\nАдминка: вкл, провайдер Hikvision NVR, URL http://192.168.222.12, admin + пароль NVR, канал 1, path пустой. События «мышь» и «SOS». Тест без агента только копит очередь.\n\nHik-Connect, ISUP (Ehome), OTAP, HEOP/AIOP — не включать, к меткам не относятся. NVR в интернет не пробрасывать.",
                        'path' => '/admin/video-surveillance',
                        'audience' => 'Техник / Supervisor+',
                    ],
                    [
                        'title' => 'Чеклист приёмки сети',
                        'description' => "Ядро: ping 192.168.20.1 с ПК; Winbox на RB5009 и CRS354; sfp-sfpplus1–2 LACP Up, без дискards.\nБездиск: PXE одного ПК → образ; 5 ПК разом — загрузка без таймаута; iperf/копирование с .10 saturates >10 Гбит суммарно на bond.\nИгры: ПК в интернете; с игрового ПК не открывается http://192.168.222.12; WOL с дашборда будит выключенный клиент.\nКамеры: 40 online в NVR; запись 24/7; с ПК лиц открывается веб NVR; с игрового — нет.\nМетки: агент в логе poll OK; «Тест метки» → тег на D1; HID с тестового ПК → тег.\nОтказ: выключить RB5009 — зал и бездиск продолжают грузиться друг у друга (L2), интернет и WOL пропадают. Выключить CRS354 — встаёт всё. Выключить бездиск — ПК не грузятся, камеры живы.",
                        'path' => null,
                        'audience' => 'Техник',
                    ],
                ],
            ],
            [
                'id' => 'tv-shell',
                'title' => 'TV Shell (Android TV / приставка)',
                'items' => [
                    [
                        'title' => 'Назначение',
                        'description' => "APK ru.compclub.tvshell — киоск на Android TV / Google TV / ТВ-приставке (не PC Qt-шелл).\n\nIdle: логин + оверлеи (6 блоков как на PC: CAM/DAT/INF).\nСессия: таймер, баланс, лаунчер приложений, SOS, продление с баланса (или QR если не хватает), HDMI (ручные кнопки + авто: сессия→HDMI1 / конец→HDMI2, настраивается в Setup).\n\nСеть без сессии режется через MikroTik (MAC/IP isolate); с сессией — restore.",
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
                        'description' => "Физический HDMI-CEC селектор шелл не эмулирует. Passthrough-переключение — через TvInputManager (Intent), если OEM отдаёт входы.\n\n1) HDMI-CEC / Auto Input Switch (обязательно на панелях у PS5/приставок):\nПри нажатии PS на геймпаде консоль шлёт CEC Active Source — многие ТВ сами переключают вход на HDMI приставки и «съедают» Android-слой. В настройках ТВ выключите:\n• HDMI-CEC / Anynet+ / Bravia Sync / Simplink / EasyLink (или отдельно «Auto Input Switch» / «автопереключение входа»)\n• при необходимости оставьте CEC только для пульта/питания, без auto-switch\nADB (если пункт спрятан; имена зависят от OEM):\n  adb shell settings put global hdmi_control_auto_device_off_enabled 0\n  adb shell settings put global hdmi_control_enabled 0\nПроверьте на конкретной модели — ключи не универсальны.\n\n2) Авто HDMI по сессии (PS booth):\nСтарт сессии → HDMI сессии (дефолт 1, консоль). Конец сессии → HDMI idle (дефолт 2). Настройка в Setup (5× тап → PIN): номера входов, ТЕСТ, idle=0 = не парковать на HDMI (остаться на Android-логине). Работает только если TvInputManager отдаёт passthrough; иначе no-op.\n\n3) HDMI passthrough / PiP (TCL, Xiaomi, Hisense и др.):\nКнопки HDMI в сессии и авто-switch — только если OEM отдаёт входы. На части прошивок переход на HDMI уводит Android в PiP/фон. Это не релейный HDMI-switcher. В сессии KioskGuard выключен (YouTube/HDMI). На idle guard снова тянет логин на передний план — на части панелей может перебить idle-HDMI; если нужен стабильный «парк» на HDMI2 после сессии, проверьте модель и при необходимости отключите авто (Setup) или используйте внешний свитчер.\n\n4) Чеклист установки панели:\n• factory reset → без Google-аккаунта → install APK → set-home-activity → Device Owner\n• выключить CEC Auto Input Switch\n• Setup: проверить список HDMI, ТЕСТ сессия/idle, привязать номера (ПС=1, idle=2)\n• отключить автообновления прошивки/магазинов\n• сеть: MAC ТВ в MikroTik isolate на idle / restore на сессии\n\n5) KioskGuard на idle: moveToFront + startActivity ~каждые 800 мс. На сессии guard выключен.",
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
                        'description' => "POST /api/shell/login (phone, pin, terminal_id) → активация брони (BookingSessionTimingService), баланс, time_remaining.\n\nОверлеи убиваются (kill/release). ui-state session_active → MikroTik restore (интернет открыт).\n\nSessionActivity: имя, баланс, таймер (локальный tick + poll balance/heartbeat ~8 с), предупреждения 10/5/1 мин.\nKioskGuard выключен — можно уходить в YouTube/HDMI.\nАвто HDMI: сразу после входа шелл переключает passthrough на HDMI сессии (дефолт HDMI1 = ПС), если OEM отдаёт входы.\nЛаунчер: LEANBACK-приложения (YouTube/Кинопоиск в приоритете), Settings/Play скрыты.\nHDMI-кнопки вручную — если TvInputManager отдаёт passthrough (иначе блок скрыт).\nSOS → POST /api/shell/sos.\nПродлить → сначала длительность (30м/1–3ч) и списание с баланса; QR-оплата только если средств не хватает.\nLAN CommandService :8787 (message, session_end, …).",
                        'path' => null,
                        'audience' => 'TV / Игрок',
                    ],
                    [
                        'title' => 'Конец сессии на TV',
                        'description' => "Триггеры: кнопка «Завершить», таймер 0, heartbeat/balance session_active=false, LAN session_end, истечение на сервере (completeExpiredSessions + isolate для kind=tv).\n\nДействия: logout API → очистка SessionStore → session_idle (isolate) → KioskGuard снова включён → Login + оверлеи → авто HDMI idle (дефолт HDMI2; 0 = пропуск).\nLock Task whitelist снова только пакет шелла.",
                        'path' => null,
                        'audience' => 'TV / Система',
                    ],
                    [
                        'title' => 'Время сессии: кабинет vs шелл',
                        'description' => "Кабинет игрока считает remaining по wall-clock: date + start_time + duration.\nШелл раньше мог брать «кривой» ends_at (+~3 ч из-за naive timestamp / timezone).\n\nСейчас Shell API (login + /api/shell/balance) использует ту же логику, что кабинет, и при перекосе чинит starts_at/ends_at (healSkewedWindow).\n\nКабинет: для active — remainingSeconds (как шелл); для опоздания до входа — softGraceRemainingSeconds (оплаченные минуты, без +grace в «Осталось»). QR book-from-idle → activateFromNow(duration), не soft-grace activate.\nПосле деплоя таймер ЛК ≈ шелл (~2 ч бронь → ~2 ч на экране).",
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
                        'description' => 'Игрок или shell отправляет SOS (периферия / помощь с входом / другое). Попадает в инциденты и бейджи сайдбара. Событие с триггером sos в /admin/video-surveillance ставит метку на NVR (раздел «Сеть клуба»).',
                        'path' => '/admin/incidents',
                        'audience' => 'Shell → Админ',
                    ],
                    [
                        'title' => 'HID-алерты',
                        'description' => 'Снимки периферии и алерты: смена / отключение / нестабильность устройств. Триггеры hid.disconnected / hid.device_changed / hid.unstable опционально ставят видео-метки на NVR (события в /admin/video-surveillance).',
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
                        'description' => "Баланс, активные брони с таймером, заказы магазина, транзакции, прогресс достижений, статус заявки на бонус за отзыв.\n\nВ шапке на мобильных/планшетах — иконка QR-сканера (на десктопе скрыта: вход с телефона). См. «Вход по QR».\n\nКнопка «Сесть за ПК» без живой сессии — заготовка быстрого входа (логика «Подключиться» ещё заглушка). При активной сессии кнопка становится «Пересесть» (см. «Пересадка на другой ПК»).",
                        'path' => '/account/dashboard',
                        'audience' => 'Игрок',
                    ],
                    [
                        'title' => 'Сканер QR (вход на ПК)',
                        'description' => "Иконка QR в навигации ЛК (только mobile/tablet, lg скрыта) на любой странице с MainLayout. Камера телефона читает QR с экрана idle PC Shell (jsQR).\n\nPOST /account/qr/redeem {token}:\n• есть бронь на этот ПК (confirmed/paid/active) → та же активация, что по PIN, challenge → consumed, шелл подхватывает вход;\n• ПК свободен, брони нет → needs_booking: выбор длительности от 60 мин шагом ±15, quote/book с баланса; не хватает денег → пополнение (Reactor Pay) и повтор «Открыть сессию»;\n• ПК занят чужой сессией → occupied.\n\nPayload QR: {APP_URL}/account/dashboard?qr={uuid} — по ссылке сканер открывается сам. TTL challenge 120 с (таблица shell_qr_challenges).",
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
                        'description' => 'Привязка ПК по HWID железа: SMBIOS UUID, иначе MAC onboard, MachineGuid только как last resort (на клоне образа он общий). check и register-terminal. После смены алгоритма места перерегистрировать один раз. Для TV используйте zone_type=tv (kind=tv) — см. раздел «TV Shell».',
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Киоск Windows (SecurityManager)',
                        'description' => "PC Shell: src/core/securitymanager.cpp — локдаун гостевой сессии Windows (реестр HKCU Policies).\n\nlockDownSystem(): DisableCMD / DisableRegistryTools / DisableTaskMgr / DisableChangePassword / DisableLockWorkstation; Explorer: NoWindowsKey, NoRun, NoDrives, NoFind, NoViewContextMenu; StickyKeys Flags=506; Shell=путь к REACTOR вместо explorer.exe.\n\nФлаг: config.ini [Security] production=true на клубном образе. Без этого гость уходит в TaskMgr/Win+R.\n\nКак снять киоск и сохранить это в образ — отдельная глава «Обслуживание образа: setup и Super Client». Не путать с TV KioskGuard и с MikroTik isolate.",
                        'path' => null,
                        'audience' => 'Shell / Техник',
                    ],
                    [
                        'title' => 'Обслуживание образа: setup и Super Client',
                        'description' => "Зачем. Гибрид: один Windows-образ на все игровые ПК + SSD/NVMe в каждом как кэш. Обычная загрузка пишет изменения гостя в writeback. После reboot writeback выкидывается — Windows, драйверы, сам Shell, лаунчеры на C: как были в образе. Без Super Client патч Valorant, обновление шелла или драйвер GPU на одном ПК до следующего ребута живёт и на остальные 39 не попадает.\n\nSuper Client (CCBoot / CCBoot Cloud) говорит серверу: этот ПК сейчас редактирует выбранный диск. Пока режим включён, запись идёт в образ (или игровой диск), а не в одноразовый writeback. Выключили Super Client и сохранили — золотой образ обновлён, остальные ПК получат его с кэша/сервера.\n\nПочему из шелла, а не из booking. Админка лицензий/WOL образом не управляет (нет API CCBoot в облаке). Техник стоит у места: Win+ПКМ → setup → включить Super Client на этом клиенте. Пароль — Admin Password CCBoot (General Options на сервере бездиска), не PIN брони и не пароль кассы.\n\nКакой диск. В setup: image | disk | both.\n• image — ОС, Shell, Visual C++, лаунчеры, античиты в Program Files на системном томе. Так делают почти всегда.\n• disk — игровой том (Steam library и т.п.). Youngzsoft не советует держать Super Client на game disk у всех ПК: том лочится на сервере, остальные грузят игры медленнее, риск порчи. Игры лучше катить с сервера или с локального SSD-кэша без SC на game disk.\n• both — только если сознательно правите и ОС, и игровой диск с одного клиента.\nПо умолчанию: image.\n\nКогда нельзя. Час пик зала (остальные грузятся дольше). Super Client уже висит на другом ПК с тем же образом. Мало места на image disk сервера (нужен запас 10–20 ГБ). Сессия гостя на этом месте — сначала logout. Не обновлять драйвер LAN/NIC в Super Client — сломает PXE/iSCSI.\n\nЧто должно быть в образе заранее. C:\\CCBootClient\\CCBootClient.exe (или путь в config.ini [Diskless] client_exe=). [Security] production=true. iCafeMenu / iCafeCloud cafe-оболочку не ставить — шелл клуба это REACTOR. [Diskless] server_ip=192.168.20.10 справочно.\n\nАлгоритм включить и править\n1) ПК в idle: экран логина GUEST, гостя нет. Сессию закрыть.\n2) Открыть setup (REACTOR CONTROL), не путать с паузой и reboot-PIN гостя:\n   • Win+ПКМ на экране логина;\n   • если киоск съел Win (NoWindowsKey) — Ctrl+ПКМ или Ctrl+клик по имени ПК (TERMINAL_ID).\n3) Блок «ОБРАЗ · SUPER CLIENT». Поле пароля = Admin Password CCBoot. Диск = image.\n4) «Включить Super Client». Шелл снимает киоск (unlockSystem + explorer), прячет себя, запускает CCBootClient.exe и жмёт Enable Super Client / тип диска / пароль / reboot. Если диалоги не поймались — «Только CCBoot Client» и те же кнопки руками в окне Youngzsoft.\n5) ПК уходит в reboot. Это норма: Super Client применяется со следующей загрузки.\n6) После reboot шелл видит флаг Super Client и киоск не ставит (explorer доступен). Снова Win+ПКМ в setup, если нужен UI шелла; либо работайте с рабочего стола.\n7) Правки: Windows Update, GPU-драйвер (не LAN), новый билд шелла, лаунчер, античит в образ, программы в Program Files. Игры, которые должны жить на SSD/игровом томе, не тащите на C: без нужды.\n8) Проверка на ЭТОМ ПК: игра/шелл стартуют. Не выключайте ПК кнопкой питания в середине записи образа.\n\nАлгоритм сохранить и выйти\n9) Снова setup → «Выключить и сохранить» (Disable Super Client + save). Пароль тот же. CCBoot спросит: сохранить образ? Да. Restore point? Да, с короткой пометкой (дата + что меняли). Затем shutdown клиента.\n10) На сервере бездиска / в iCafeCloud PC больше не красный Super Client. Образ записан. Остальные места: следующий boot или refresh кэша — уже новая ревизия.\n11) Проверка на втором ПК (не том, на котором только что писали, если кэш ещё старый): логин шелла, запуск одной игры. Если второй ПК встал на старый кэш — refresh cache этого клиента в CCBoot, не «ещё раз Super Client на всех».\n12) Не оставляйте Super Client включённым «на потом»: writeback выключен, зал тормозит, game disk может быть locked.\n\nТолько киоск, без Super Client. Кнопка «Снять киоск» даёт explorer здесь и сейчас. После обычного reboot всё откатится. Так смотрят логи, не так обновляют золотой образ.\n\nНе путать\n• Pause / «пин после перезагрузки» на дашборде — PIN гостя, чтобы войти в ту же бронь. Образ не трогает.\n• Super Client с консоли iCafeCloud (ПК → Enable superclient) — тот же режим, если кнопки в CCBoot Client нет (ветка Cloud). Итог тот же: reboot → правки → Disable + save.\n• Booking /admin/licenses — пул Steam-аккаунтов, не смена VHD.\n\nКод: SetupScreen.qml блок Super Client; CcbootSuperClient; SecurityManager::unlockSystem; вход Main.qml openSetupScreen (Win/Ctrl+ПКМ). Документ сети: VLAN 20, сервер .10 — глава «Бездисковый сервер».",
                        'path' => null,
                        'audience' => 'Техник / Shell',
                    ],
                    [
                        'title' => 'Тома гибрида: C: образ / D: кэш',
                        'description' => "Раскладка на клубном образе (не путать с чистым бездиском без SSD):\n\n• C: — Windows из образа CCBoot. Writeback гостя выкидывается после reboot. Сюда ставится REACTOR Shell, VC++, лаунчеры в Program Files, античиты. Не класть библиотеку Steam и логи шелла.\n• D: (или том с меткой GAMES) — Steam library, инсталлы, ShellData (логи, оверлеи, machine-cache лаунчеров). config.ini [Storage] data_root=D:/ShellData, [Paths] steam=D:/Steam games=D:/Games.\n• Junction C:\\Program Files (x86)\\Steam → D: допустим, но Paths всё равно явные. Каталог игр в /admin/licenses exe_path = D:\\Games\\... — тот же путь на всех 40 ПК.\n• Нет тома кэша → шелл не стартует игры, на дашборде плитка «кэш».\n• iCafeMenu / iCafeCloud cafe-оболочку не ставить — шелл клуба это REACTOR.\n• Сохранение образа — только CCBoot Super Client (setup), не кнопка в шелле и не booking.",
                        'path' => null,
                        'audience' => 'Техник / Shell',
                    ],
                    [
                        'title' => 'Техрежим (не Super Client)',
                        'description' => "Кнопка «Обслуживание» на дашборде (рядом с reboot, тот же PIN паузы) или setup «Снять киоск»: SecurityManager.unlockSystem() + heartbeat maintenance=true. Booking не шлёт shutdown и не отдаёт место в бронь/пересадку. Выход: «Вернуть киоск» / баннер «Завершить и reboot» → lockDownSystem() + reboot, чтобы writeback гостя не остался в сессии.\n\nНе путать с Super Client: техрежим открывает explorer на этом ПК; Super Client пишет в золотой образ.",
                        'path' => '/admin/dashboard',
                        'audience' => 'Техник / Shell',
                    ],
                    [
                        'title' => 'Логин сессии',
                        'description' => "Основной способ: телефон + PIN брони + terminal_id → POST /api/shell/login → активация сессии, остаток времени, баланс и settings_pack (Cloud Saves).\n\nДублирующий: QR на экране входа рядом с формой PIN (см. «Вход по QR»). PIN не убираем и не прячем.",
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Вход по QR',
                        'description' => "Дубль PIN: гость сканирует QR терминала в ЛК → сессия на этом ПК активируется без ввода PIN на клавиатуре шелла.\n\nShell (idle):\n• POST /api/shell/qr/challenge {terminal_id} → token, expires_at, qr_payload;\n• картинка QR (qr_payload) на панели «ВХОД ПО QR»;\n• poll GET /api/shell/qr/status?token= ~1.5 с; status=consumed → тот же loginSucceeded, что после PIN; expired → новый challenge.\n\nЛК: иконка QR-сканера (mobile) → redeem / quote / book (см. «Сканер QR»).\nНет брони на ПК: бронь «с сейчас» на выбранную длительность (≥60 мин, шаг 15), оплата с баланса или топап, затем activate+consume.\n\nКод: ShellQrLoginService, ShellQrLoginController, NetworkManager::requestQrChallenge; TTL 120 с; тесты tests/Feature/ShellQrLoginTest.php.\nМиграция: shell_qr_challenges.",
                        'path' => null,
                        'audience' => 'Shell / Игрок',
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
                        'description' => "POST /api/shell/power/heartbeat (~30 с) + MAC NIC + cache_ok/free_gb/data_root + maintenance → online / очередь WOL / плитка кэша.\nPOST /api/shell/power/offline при штатном уходе.\nВ ответах logout/balance/poll может прийти power_action=reboot|shutdown по desired питания (бронь ± warmup); в техрежиме всегда none.\nMagic packet шлёт MikroTik из /api/power/wol-targets, не шелл и не облако напрямую. Настройка токена и warmup — .env CLUB_*; статусы на дашборде. Подробности — «Питание ПК» в Конфигурации.",
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
                        'description' => "Hold-to-talk во время сессии: запись с микрофона → POST /api/shell/ai-assistant → SpeechKit STT (или Whisper) → LLM (DeepSeek/OpenAI из админки) → SpeechKit/OpenAI TTS в наушники. Нужна активная бронь на ПК.\n\nПосле логина: POST /api/shell/voice-greeting — короткое персональное приветствие в колонки лобби (имя, первый визит / любимые игры). Промпты, голос и ключи — /admin/ai-assistant; Shell только шлёт аудио/terminal_id и воспроизводит ответ.",
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
                        'description' => "Вход на ПК: телефон+PIN (основной) или QR из ЛК (дубль) — оба вызывают BookingSessionTimingService::activate / resume после пересадки.\n\nРанний старт сдвигает ends_at с сохранением оплаченной длительности (duration — источник истины при timezone-skew). Опоздание: если нет следующей брони на ПК — до 30 мин ожидания без списания, затем списание; при следующей брони списание с starts_at. Grace не блокирует слот после ends_at. No-show — когда эффективное время истекло без входа. Самоотмена гостем с возвратом — до дедлайна из /admin/booking-settings (по умолчанию за 2 ч до starts_at); позже отмена недоступна, оплата удерживается.",
                        'path' => null,
                        'audience' => 'Система / Shell',
                    ],
                    [
                        'title' => 'Пересадка на другой ПК',
                        'description' => "Статус: реализовано (самообслуживание).\n\nAPI Shell: GET /api/shell/transfer/targets, POST /api/shell/transfer/preview|confirm (terminal_id + target_computer_id) — список свободных ПК. ЛК: GET /account/transfer/targets отдаёт targets + map_config/computers/occupied_ids/selectable_ids; модалка «Пересесть» показывает ClubMap. Shell UI: «ПЕРЕСЕСТЬ» (список, без SVG-карты).\n\nПравила:\n• только status=active, целевой ПК свободен до ends_at, тот же клуб, kind=pc;\n• дороже: доплата с баланса с сохранением времени; если денег мало — укоротить ends_at (prepaid value + баланс / новый ₽/ч);\n• дешевле: без возврата, время не растёт;\n• доплата считается от оплаченной ставки брони (price/duration), а не только от текущего hourly исходного ПК — иначе пакет 375 ₽ при hourly 400 даёт ложное «тариф тот же»;\n• бронь не complete: меняются computer_id/pc_ids; старый Shell получает session_active=false на balance-poll (soft-kick, без logout-complete);\n• вход на новом ПК — при пересадке выдаётся новый PIN; бронь уже status=active на целевом ПК (Shell UI сам не открывается). Login по PIN после пересадки — resume без повторного activate;\n• если PIN не ввели за 10 мин (transfer_pending_at) — откат на исходный ПК (reclaimAbandonedTransfers в reactor:update-statuses и /api/shell/balance), целевой снова available.\n\nЗаказы бара: pc_name = ПК активной сессии на момент заказа (см. «Магазин»).\n\nНе путать с «Сесть за ПК» без живой сессии (заготовка входа) и с gift-причиной «Пересадка по вине клуба».",
                        'path' => '/account/dashboard',
                        'audience' => 'Игрок / Shell',
                    ],
                    [
                        'title' => 'Автозакрытие сессий',
                        'description' => 'Команда reactor:update-statuses каждую минуту: no-show (неначатые с истекшим эффективным временем + settle чека), закрытие активных сессий по ends_at, откат незавершённых пересадок без PIN (10 мин), дозакрытие зависших deferred-чеков, busy/available ПК, пересчёт питания ПК (desired/WOL-state). Для kind=tv — isolate в очередь MikroTik.',
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
