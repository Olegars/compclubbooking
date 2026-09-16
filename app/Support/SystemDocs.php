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
                        'description' => 'Карта и статусы ПК, сводка по выручке магазина, активным сессиям и новым гостям. Поиск игрока по телефону, пополнение депозита с кассы, выдача бонусного времени. Клик по плитке ПК: здоровье (линк Мбит, flap патч-корда за смену, износ SSD, Steam/Epic с диска, LAN-seed/mirror :port) и очередь Super Client (шелл забирает команду в heartbeat; пароль CCBoot только в config.ini на месте). Жёлтая плитка «flap N» — деградация кабеля; «mirror d:» — этот ПК раздаёт патчи по VLAN. Тикет «Проверить свитч/микрик» — лента инцидентов, не плитка. Владелец: «Освободить компьютер» закрывает залипшую сессию, если шелл убили без logout.',
                        'audience' => 'Активный админ',
                    ],
                    [
                        'title' => 'Очередь заказов',
                        'description' => "Активные заказы бара и магазина (в т.ч. с Shell ПК). Смена статусов, скан кодов маркировки перед выдачей, глобальный HID-сканер для списания КМ в заказ.\n\nЗвук при новом заказе: на /admin/orders очередь опрашивается ~каждые 7 с; при появлении нового id играется /sounds/notification.mp3 и тост «Новый заказ в очереди» (нужна открытая вкладка очереди; браузер может требовать жест пользователя для Audio). В сайдбаре — бейдж pending_orders.\n\nЗаказы к будущей брони (status=scheduled) в очередь не попадают: бар видит их за 7 мин до старта сессии или когда гость в шелле нажмёт «Я на месте». Кухонный слип печатается только в этот момент.\n\nАвтопечать чек-ордера ESC/POS (один Ethernet-принтер бара, TCP :9100): при создании заказа (Shell/магазин) в очередь order_kitchen_prints кладётся слип вида «ПК-08 | #123» + строки «2x Энергетик». Кнопки «Печать» нет — сразу в очередь. Облако само на принтер не ходит: LAN-агент scripts/kitchen-print-agent.ps1 pull’ит GET /api/kitchen/print-targets?token=… и шлёт raw ESC/POS, затем POST /api/kitchen/print-applied. Env: KITCHEN_PRINT_ENABLED, KITCHEN_PRINT_RELAY_TOKEN (или CLUB_WOL_RELAY_TOKEN), на агенте — KITCHEN_API_BASE / KITCHEN_PRINT_TOKEN / KITCHEN_PRINTER_HOST / KITCHEN_PRINTER_PORT. Не путать с копией фискального чека в /admin/transactions.\n\nСтраховка: reactor:check-quality → инцидент late_order, если pending дольше 5 минут (см. «Контроль качества заказов»; для отложенных заказов часы идут от released_at).\n\nДоставка: pc_name = ПК сессии или забронированный ПК для pre-session заказа.",
                        'path' => '/admin/orders',
                        'audience' => 'Админ / Бар / Техник',
                    ],
                    [
                        'title' => 'Склад бара',
                        'description' => 'Склад бара/кухни (/admin/inventory) — не путать со складом комплектующих магазина ПК (/admin/store/warehouse).\n\nКаталог, приёмка сканом, списание и угощение с причиной (просрочка / бой / комп) — пишется в journal stock_movements, чтобы не всплывало как «кража» на пересменке. КМ — по DataMatrix; немеченое — количеством.\n\nВ режиме приёмки можно загрузить фото накладной (УПД / ТОРГ-12): DeepSeek (deepseek-flash) читает строки в черновик. На склад с фото ничего не падает — админ пикает реальный товар сканером. Панель показывает накладная vs факт; закрыть можно, только когда количества совпали и нет лишних сканов. Тогда же, если есть поставщик и закуп, открывается один счёт в долгах.\n\nСебестоимость: при приёмке указывается закупочная цена (unit_cost) и поставщик → создаётся партия inventory_batches (FIFO), пересчитывается средневзвешенная cost_price на карточке, при поставщике и цене > 0 — открытый счёт в долгах. При продаже/списании себестоимость (COGS) списывается FIFO и пишется в meta движения.\n\nМин. остаток (min_stock): если задан и stock ≤ порога, reactor:check-quality создаёт инцидент low_stock (без дублей, пока не закрыт).',
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
                        'description' => "Передачу инициирует заступающий админ из личного кабинета: «Принять смену» у ресепшена, взгляд в камеру. Когда система подтвердит присутствие, уходящему блокируются операции (плашка «Передача смены»), у пришедшего — режим приёма.\n\nДальше заступающий сканирует товар в холодильниках HID-сканером, вводит количество, Ок; повторный скан той же позиции открывает ранее введённое число. Когда всё с остатком посчитано, расхождения подсвечиваются красным — кнопка «Принять смену». Недостача, которую сдающий не списал со склада, записывается ему убытком в личный кабинет.\n\nПосле приёма у уходящего «Смена сдана» и выход из админки; принявший становится активным админом. Стажёр смену не принимает.",
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
                        'title' => 'Личный кабинет (клуб)',
                        'description' => "Страница /admin/salary для админа зала, стажёра и управляющего. Владелец сюда не попадает: у него отдельный кабинет /admin/cabinet.\n\nКалендарь слотов клуба (день/ночь или сутки): админ занимает lead, стажёр — intern. «Принять смену» у ресепшена — только админ зала. Начисления за закрытую смену (pay_type=shift) или оклад (monthly), штрафы/убытки, вывод.\n\nНовая регистрация клуба: /admin/login → «Устроиться» (роль intern, employment_pending) → в этом же кабинете правила, паспорт, проверка управляющим, визит в клуб, биометрия (заглушка), правила ПБ. Пока анкета открыта — только кабинет (и приёмка смены у неактивного админа).\n\nСкачать APK 0451 Ctrl: кнопка на этой странице (скрыта внутри приложения), файл GET /admin-app.apk. Сотрудник магазина качает отдельный 0451 Store (см. «Магазин компьютеров»).",
                        'path' => '/admin/salary',
                        'audience' => 'Админ / Стажёр / Supervisor+',
                    ],
                    [
                        'title' => 'Админское приложение (0451 Ctrl)',
                        'description' => "Android-обёртка только админки клуба, не путать с магазинным APK (space.club0451.store), клиентским APK (space.club0451.client) и TV Shell.\n\nПакет space.club0451.admin, имя на устройстве «0451 Ctrl», исходники C:\\Qt\\admin_apk. WebView открывает /admin/login. UA дополняется CompClubAdmin/… — по нему сайт режет публичные страницы и портал магазина (middleware RestrictPublicInAdminApp + редирект на /admin/login в WebView и в app.js). Разрешены пути /admin/*. В бандл: Admin-страницы и AdminLogin (StoreLogin/StoreHire не входят).\n\nСкачать: кнопка на /admin/salary у админа клуба (скрыта внутри приложения). Файл GET /admin-app.apk (storage/app/apk/admin0451.apk). Самообновление: GET /admin-app.json {version_code, version_name, apk_url, size}. После выкладки APK поднять ADMIN_APP_VERSION_CODE / ADMIN_APP_VERSION_NAME в .env (config/admin_app.php).\n\nСборка (JDK 17):\n   cd C:\\Qt\\admin_apk\n   set JAVA_HOME=C:\\Qt\\jdk-17\n   gradlew.bat assembleRelease",
                        'path' => '/admin-app.apk',
                        'audience' => 'Админ / Техник',
                    ],
                    [
                        'title' => 'Приложение владельца (0451 Boss)',
                        'description' => "Android-обёртка всей админки бэкенда для владельца, не путать с 0451 Ctrl (зал), 0451 Store (магазин) и клиентским APK.\n\nПакет space.club0451.boss, имя на устройстве «0451 Boss», исходники C:\\Qt\\boss_apk. Отдельный фронт не нужен: WebView открывает /admin/login и те же Inertia-страницы Admin, что и сайт. UA дополняется CompClubBoss/… — по нему сайт режет публичные страницы (middleware RestrictPublicInBossApp + редирект в WebView и в app.js). Разрешены /admin/* (зал, экономика, персонал, налоги, магазин, локации) и /store/*. В бандл: все Admin-страницы, AdminLogin, StoreLogin, StoreHire.\n\nВход только с ролью owner; «Устроиться» скрыто. Скачать: кнопка на /admin/cabinet у владельца (скрыта внутри приложения). Файл GET /boss-app.apk (storage/app/apk/boss0451.apk). Самообновление: GET /boss-app.json {version_code, version_name, apk_url, size}. После выкладки APK поднять BOSS_APP_VERSION_CODE / BOSS_APP_VERSION_NAME в .env (config/boss_app.php).\n\nСборка (JDK 17):\n   cd C:\\Qt\\boss_apk\n   set JAVA_HOME=C:\\Qt\\jdk-17\n   gradlew.bat assembleRelease\n\nТо же приложение для iPhone: C:\\Qt\\boss_iphone (Xcode, bundle space.club0451.boss, тот же UA CompClubBoss). IPA на Windows не собирается — открыть 0451Boss.xcodeproj на Mac, выбрать Team, Run / Archive.",
                        'path' => '/boss-app.apk',
                        'audience' => 'Owner',
                    ],
                    [
                        'title' => 'Личный кабинет владельца',
                        'description' => "Отдельная страница /admin/cabinet, не /admin/salary и не /store/cabinet. Админ зала и сотрудник магазина в этот кабинет не пускаются; владелец с /admin/salary уходит сюда.\n\nСводка: смена ресепшена, доход УСН за сегодня, очереди бара и магазина, инциденты, Avito, штат и заявки на проверке, локации. Быстрые ссылки в дашборд, штат, аналитику, налоги, локации, заказы магазина, тесты системы.\n\nНет календаря слотов, «Принять смену», вывода зарплаты и рабочего стола сборщика — это кабинеты сотрудников.\n\nСайдбар: пункт «Кабинет владельца» в блоке «Личное». Скачать 0451 Boss — кнопка на этой странице.",
                        'path' => '/admin/cabinet',
                        'audience' => 'Owner',
                    ],
                    [
                        'title' => 'Тесты системы',
                        'description' => "Страница /admin/system-tests только для роли owner (сайдбар Конфигурация и кабинет владельца). «Запустить все тесты» гоняет живые проверки и PHPUnit по файлам; «Вывести результаты в PDF» открывает печатный лист (диалог «Сохранить как PDF»). Кнопки по контурам: БД, кэш, файлы, ЮKassa /me, касса, heartbeat шелла, SMART/линк, WOL-токен, diskless, вентиляторы, DMX, кухня, видео-метки, Wi-Fi, клипы, ИИ, Avito, QuickFox. Не включает ПК, не бьёт чек, не шлёт SMS и Art-Net.\n\n«Здоровье станций»: линк NIC, nic_flap_count (патч-корд), SMART SSD, мёртвый кэш, drift образа, открытый тикет hardware_switch_fault («свитч/микрик»). Warn, не fail клуба.\n\nОтдельно — кнопки PHPUnit (tests/Feature и tests/Unit): php artisan test по файлу или весь набор. Только sqlite :memory: — DB_* из php-fpm принудительно сбрасываются (иначе RefreshDatabase сносит боевой Postgres). Из php-fpm PHP_BINARY — FPM, поэтому ищем CLI (PHP_CLI_BINARY=/usr/bin/php). Сам прогон — отдельный CLI-процесс (owner:run-phpunit), страница опрашивает статус: иначе nginx на длинном phpunit:all отвечает 504. На сервере без require-dev кнопка напишет, что PHPUnit нет. Не замена Zabbix: это ручной прогон по кнопке, не ping WAN и не баланс SMS-шлюза.",
                        'path' => '/admin/system-tests',
                        'audience' => 'Owner',
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
                        'description' => "Отдельный контур сборки и продажи ПК (не путать со складом бара /admin/inventory).\n\nПоток: смета клиенту → позиции из каталога ITP/QuickFox и/или со склада → заказ недостающего у поставщика → приёмка на склад с серийниками → сборка ПК → заказ магазина / выдача → гарантия (QR, талон) → QR-паспорт /pc/{token} (Digital Twin: кто собирал, видео стола, серийники, остаток гарантии) → при обращении: ремонт / возврат в сборку / замена детали.\n\nРазделы: /admin/store/estimates, /warehouse, /built-pcs, /orders, /warranty, /clients, /avito. Локации — /admin/store/locations (type club / store / both); переключение у owner влияет на выборку склада/смет.\n\nЛюди магазина: вход /store/login, устройство /store/hire, после оформления домашний кабинет /store/cabinet. Клубный /admin/login их не пускает.",
                        'path' => '/admin/store/estimates',
                        'audience' => 'Магазин / Owner',
                    ],
                    [
                        'title' => 'Роли магазина',
                        'description' => "STORE_ONLY (isStoreRole): assembler (сборщик, ставка 2200 ₽/смена), store_manager (менеджер, 2500 ₽/смена), senior_manager (старший менеджер, оклад 3500 ₽/мес.). Владелец в STORE_ROLES: видит клуб и магазин, входит на /admin/login, домашний маршрут — дашборд, личный кабинет — /admin/cabinet.\n\nПрава (методы Admin):\n• canManageStoreCatalog / canManageStoreInventory — менеджер, старший, owner: сметы, каталог, склад (запись), клиенты (запись), Avito, назначение сборщика, создание заказа.\n• Сборщик: смотрит склад/сборки/заказы/гарантии; клиентов только читает; сметы не создаёт. В кабинете видит ready-сметы к сборке.\n• Взять заказ (new → assembling) и вести assembling/ready — сборщик и выше. «Готов» только после check_build.\n• canCancelStoreOrders / canCloseWarranties — только старший и owner.\n\nКлуб (admin / intern / supervisor) в магазин не пускается. Магазинные роли в операции зала (дашборд, бар, пересменка) не пускаются. Кабинет магазина — /store/cabinet, не /admin/salary.\n\nСайдбар: «Личное» (кабинет магазина) + «Магазин». В шапке должность, без «Смена закрыта» зала. «О системе» (/admin/docs) — у всех вошедших сотрудников магазина.",
                        'path' => '/admin/staff',
                        'audience' => 'Owner / Старший менеджер',
                    ],
                    [
                        'title' => 'Вход и устройство в магазин',
                        'description' => "Портал отдельно от клуба. GET /store → /store/login (янтарный Store).\n\nВход POST /store/login: только store_manager / assembler / senior_manager. Админ зала / стажёр / владелец — отказ «войдите на /admin/login». С клубного /admin/login магазинных ролей тоже разворачивает на /store/login. Ссылки между формами внизу страницы (в APK скрыты). Выход магазинного сотрудника → /store/login.\n\nРегистрация POST /store/register «Устроиться»: имя, email, пароль, должность сборщик или менеджер (старшего так не создать — его заводит owner/supervisor в /admin/staff). Пишется employment_pending, ставка/pay_type по должности, club_id первой локации type=store или both. Дальше GET /store/hire: правила работы → паспорт (скан) → «На проверке» → визит в магазин → биометрия (заглушка, ставит управляющий) → правила ПБ. Роль при приёме не сбрасывается в intern.\n\nПока анкета открыта: склад, заказы, /store/cabinet закрыты — только /store/hire и выход. После ПБ — /store/cabinet.\n\nДемо-сид AdminSeeder: store@0451.space (менеджер), build@0451.space (сборщик), senior-store@0451.space (старший); пароль как у остальных демо-аккаунтов клуба.\n\nAPK 0451 Store пускает /store/* и разделы магазина; 0451 Ctrl /store режет на /admin/login; клиентский APK /store режет на лендинг.",
                        'path' => '/store/login',
                        'audience' => 'Сборщик / Менеджер',
                    ],
                    [
                        'title' => 'Личный кабинет магазина',
                        'description' => "Домашняя страница после входа (homeRoute): /store/cabinet. Не путать с устройством /store/hire, с кабинетом админа зала /admin/salary и с кабинетом владельца /admin/cabinet.\n\nРабочий стол по роли:\n• Сборщик — новые без исполнителя и свои в работе; кнопка «Взять в работу» (POST статус assembling, assignee=я); ПК на сборке (свои и без сборщика); гарантии claimed по своим заказам/сборкам; сметы только status=ready (к сборке). Счётчики: новые, мои в работе, к выдаче, гарантии.\n• Менеджер — все открытые заказы new/assembling/ready, активные сметы, сборки, claimed; плюс сметы в плитках и ссылки Avito/клиенты. Не отменяет заказы и не закрывает гарантии.\n• Старший — то же + список команды магазина локации, can_cancel и can_close_warranty, непрочитанный Avito.\n\nБыстрые ссылки ведут в разделы магазина. Календарь и вывод зарплаты — ниже на той же странице (см. «Смены и расчёт магазина»).\n\nСкачать APK 0451 Store: кнопка на этой странице (скрыта внутри приложения), файл GET /store-app.apk.",
                        'path' => '/store/cabinet',
                        'audience' => 'Сборщик / Менеджер / Старший',
                    ],
                    [
                        'title' => 'Приложение магазина (0451 Store)',
                        'description' => "Android-обёртка только портала магазина, не путать с админским APK (space.club0451.admin) и клиентским (space.club0451.client).\n\nПакет space.club0451.store, имя на устройстве «0451 Store», исходники C:\\Qt\\shop_apk. WebView открывает /store/login. UA дополняется CompClubStore/… — по нему сайт режет клуб и публичные страницы (middleware RestrictClubInStoreApp + редирект на /store/login в WebView и в app.js). Разрешены /store/*, /admin/salary, /admin/store/*, /admin/docs. В бандл: StoreCabinet, Store-страницы, StoreLogin, StoreHire.\n\nСкачать: кнопка в кабинете /store/cabinet у сотрудника магазина (скрыта внутри приложения). Файл GET /store-app.apk (storage/app/apk/store0451.apk). Самообновление: GET /store-app.json {version_code, version_name, apk_url, size}. После выкладки APK поднять STORE_APP_VERSION_CODE / STORE_APP_VERSION_NAME в .env (config/store_app.php).\n\nСборка (JDK 17):\n   cd C:\\Qt\\shop_apk\n   set JAVA_HOME=C:\\Qt\\jdk-17\n   gradlew.bat assembleRelease",
                        'path' => '/store-app.apk',
                        'audience' => 'Сборщик / Менеджер / Старший',
                    ],
                    [
                        'title' => 'Смены и расчёт магазина',
                        'description' => "Тот же календарь слотов локации, что у зала, но запись kind=store: не занимает место админа (lead) и стажёра. Несколько сотрудников магазина могут быть на одном окне. Отмена — за 48 часов до начала, как у клуба.\n\npay_type=shift (сборщик, менеджер): после ends_at слота при заходе в кабинет начисляется base_rate, period_key ss:{booking_id}, причина «Смена магазина ДД.MM.ГГГГ ЧЧ:ММ». Таблица «Смены магазина»: запланирована / на смене / закрыта.\n\npay_type=monthly (старший менеджер по умолчанию): оклад как у клуба, слоты можно брать для графика, отдельной ставки за слот нет.\n\nШтрафы из /admin/staff и вывод — общие с клубным кабинетом. Пересменка холодильников и камера ресепшена к магазину не относятся.",
                        'path' => '/store/cabinet',
                        'audience' => 'Сборщик / Менеджер / Старший',
                    ],
                    [
                        'title' => 'Локации магазина',
                        'description' => "Карточки клубов/магазинов: /admin/store/locations. Тип type: club (только зал), store (только магазин), both. Саморегистрация магазина сажает кандидата на первую локацию store или both.\n\nВладелец переключает текущую локацию в шапке (POST /admin/store/location/switch) — от неё зависят склад, сметы, кабинет и команда старшего менеджера. Сотрудник магазина привязан к своему club_id, чужие локации не видит.",
                        'path' => '/admin/store/locations',
                        'audience' => 'Owner',
                    ],
                    [
                        'title' => 'Сметы',
                        'description' => "Смета = комплектация ПК для клиента. Статусы: draft → agreed → procuring → ready → converted / cancelled. Создаёт и ведёт менеджер / старший / owner; сборщик в кабинете видит только ready.\n\nПозиции набираются из каталога поставщика (пикер: поиск, чипы фильтров, картинки с лайтбоксом) или со склада. В шапке формы — живые итоги продажи и закупки.\n\nPDF: кнопка «PDF» в карточке/форме → /admin/store/estimates/{id}/pdf (A4, продажные цены; в диалоге печати — «Сохранить как PDF»).\n\n«Цены API» — обновить цены/остатки по sku сметы. «Заказать недостающее» — заказ в QuickFox (EXT), запись store_purchases. «Принять на склад» — попап: серийник и комментарий по каждой позиции → комплектующие в статусе reserved под смету. «В заказ магазина» — convert: продажа со склада, статус sold.\n\nПозиции: planned / from_stock / to_order / ordered / received.",
                        'path' => '/admin/store/estimates',
                        'audience' => 'Менеджер / Старший / Owner',
                    ],
                    [
                        'title' => 'Каталог поставщика (QuickFox / ITP)',
                        'description' => "Локальный кэш store_supplier_catalog_* из B2B ITP (QuickFox API).\n\nСинк: artisan store:sync-supplier-catalog (по cron schedule в 09:00 Europe/Moscow). Upsert по sku: не затирает DeepSeek-разметку корпусов (case_*) и кэш картинок; при смене name/part разметка корпусов сбрасывается. Позиции, исчезнувшие из прайса, удаляются. Цены/остатки — get_active_products (лимит API ~10/час): «в наличии» только с ценой; при синке каталога цены сначала обнуляются, затем выставляются активные.\n\nКартинки: products_clients_images → прокси /admin/store/estimates/catalog-image/{sku}. Корпуса: store:classify-cases (09:40) + DeepSeek (DEEPSEEK_API_KEY) — цвет / стекло / ATX.\n\nEnv: STORE_QUICKFOX_DOMAIN (без /api/2), LOGIN, PASSWORD, опционально CATEGORY_IDS, пути CATALOG_TREE / PRODUCTS. Гарантия из прайса: warranty («0» = 12 мес.) → warranty_months при приёмке на склад.\n\nОтмены заказа у поставщика через API нет — только правка строк order_items.",
                        'path' => '/admin/store/estimates',
                        'audience' => 'Менеджер / Система',
                    ],
                    [
                        'title' => 'Склад комплектующих',
                        'description' => "Учёт штучных комплектующих (store_components): тип, конструктор названия + specs, серийники, закупка, поставщик, гарантия (мес.), статусы in_stock / reserved / used / sold / repair / written_off.\n\nВ таблице нет количества (всегда 1) и «оригинала» (original_name хранится, заполняется сверкой сборки). Есть дата поступления. Клик по строке — карточка: EXT заказа поставщика и sku (если пришло из закупки), остаток гарантии от даты поступления, продажа (клиент, кто продал, сборка, дата).\n\nПроданные нельзя edit/del. Приход вручную или сканером серийника; приёмка из сметы пишет EXT/sku в связь purchase_item.\n\nРемонт: статус repair, строка «передана в ремонт дата»; связь со сборкой сохраняется.",
                        'path' => '/admin/store/warehouse',
                        'audience' => 'Менеджер / Сборщик',
                    ],
                    [
                        'title' => 'Сборки ПК',
                        'description' => "store_built_pcs: комплектация из складских позиций (статус used), клиент, сборщик, серийник сборки (10 цифр), продажа. Печать QR/талона гарантии с карточки сборки — QR ведёт в паспорт /pc/{token}. Ручная загрузка mp4 сборки с карточки.\n\nСверка сборки: POST /api/build-verify (токен STORE_BUILD_VERIFY_TOKEN) — сопоставление серийников с ожидаемой комплектацией, original_name, замена деталей. Успешная сверка закрывает интервал записи на камере стола.",
                        'path' => '/admin/store/built-pcs',
                        'audience' => 'Менеджер / Сборщик',
                    ],
                    [
                        'title' => 'Заказы магазина',
                        'description' => "Заказы продажи ПК/комплектующих клиенту магазина (store_orders): статусы new → assembling → ready → issued / cancelled / returned. Назначение сборщика (менеджер+), позиции со склада. Сборщик берёт new в assembling из кабинета или со страницы заказов; без assignee подставляется он сам. «Готов» только после успешной сверки check_build. cancelled/returned — старший менеджер / owner: комплектующие на склад, сборка cancelled. При выдаче (issued) сборка и комплектующие уходят в sold; создаётся/обновляется гарантия.\n\nПромокод Lucky Seat (RX-*****, 10% периферии): поле при создании заказа, скидка с total, сверка телефона клиента с владельцем кода. Не клубный промокод /admin/promocodes.",
                        'path' => '/admin/store/orders',
                        'audience' => 'Менеджер / Сборщик',
                    ],
                    [
                        'title' => 'Гарантии',
                        'description' => "store_warranties: срок STORE_WARRANTY_MONTHS (по умолчанию 12), ремонт STORE_REPAIR_DAYS (45). Статусы active / claimed / closed. Снимок комплектации build_snapshot.\n\nВ списке — остаток гарантии сборки; кнопка Active красная, если в сборке есть деталь в repair. Клик — попап: комплектующие, у каждой остаток гарантии (дни от поступления + warranty_months), кнопки «В ремонт» / «Вернуть в сборку» / «Списать со склада» (замена: новая деталь с пометкой «замена ID…», старая written_off, в сборке подмена). Закрыть гарантию (closed) — старший менеджер / owner.\n\nПечать: QR (HTML 80 мм / POS-очередь), гарантийный талон A4. QR кодирует URL паспорта /pc/{token} (камера телефона открывает страницу). Претензии в API ITP нет — оформление у поставщика вручную (warranty@ / claims@ / ЛК B2B).",
                        'path' => '/admin/store/warranty',
                        'audience' => 'Менеджер / Сборщик / Старший',
                    ],
                    [
                        'title' => 'QR-паспорт ПК (Digital Twin)',
                        'description' => "Наклейка на корпусе проданного ПК: QR кодирует URL GET /pc/{token} (токен 32 символа, не серийник). Камера телефона открывает мобильную страницу без входа. Раньше в QR был текст «S/N / гарантия до» — такие наклейки нужно перепечатать с карточки сборки или гарантии (HTML 80 мм / POS).\n\nНа странице: кто собирал (только имя), таймлайн (старт сборки, сверка серийников, готов, выдача, ремонт), серийники деталей и остаток гарантии каждой онлайн. Телефон клиента и полное ФИО не показываются. Ролик — GET /pc/{token}/video.\n\nВидео сборки — камера стола сборщика через NVR. Заказ → assembling ставит метку store.assembly_start; сверка/ready — store.assembly_done и очередь store_assembly_clip_jobs. Тот же LAN-агент scripts/hikvision-marker-agent.ps1 (нужен ffmpeg в PATH) pull GET /api/video/assembly-clip-targets, режет интервал с NVR (RTSP playback) и POST /api/video/assembly-clips. Канал: STORE_ASSEMBLY_NVR_CHANNEL или канал события / default_channel. Максимум минут: STORE_ASSEMBLY_CLIP_MAX_MINUTES (20). Пока ролик не выгрузился — на паспорте текст ожидания. Ручная загрузка mp4 с карточки сборки.\n\nПосле деплоя: миграция public_token / assembly_* / clip jobs; канал стола в «Видеонаблюдении». Тесты: StorePcPassportTest. Код: StorePcPassportService, WarrantyQr::payload.",
                        'path' => '/admin/store/built-pcs',
                        'audience' => 'Покупатель / Магазин',
                    ],
                    [
                        'title' => 'Клиенты магазина',
                        'description' => 'Отдельная база клиентов сборок/гарантий (store_clients): имя, телефон. Привязка к сметам, заказам, сборкам и гарантиям. Сборщик только читает; создать/править — менеджер+.',
                        'path' => '/admin/store/clients',
                        'audience' => 'Менеджер / Сборщик',
                    ],
                    [
                        'title' => 'Avito (системные блоки)',
                        'description' => "Раздел /admin/store/avito: один аккаунт магазина, только настольные ПК (ноутбуки и прочие категории не выгружаются).\n\nВкладка «Конфигурации»: вручную собираются шаблоны из абстрактных комплектующих (комплектующие StoreAvitoPartsCatalog — CPU по сокетам AM4/AM5/LGA1700/LGA1851, ОЗУ DDR4/DDR5 16/32 ГБ, SSD M.2 256/512, БП 500–850 Вт). Галки AM4/AM5/1700/1851/DDR4/DDR5 фильтруют список и селекты. У каждой конфигурации sort_order (инкремент); генерация берёт следующую включённую по кругу (last_config_id). Живые SKU (плата, видеокарта, корпус) подбираются из каталога поставщика под сокет/DDR/объём/ватты.\n\nКаждый час cron store:generate-avito-ads (если включено) собирает N объявлений. Цена = сумма закупок из каталога + наценка + корпус в цене, скидка от 60к/100к, округление.\n\nКнопка «Сгенерировать» в админке запускает artisan в фоне (не в HTTP) — иначе nginx даёт 504. Заголовок и описание собираются алгоритмом из SKU каталога, без DeepSeek.\n\nXML-фид GET /avito/{token}/feed.xml. Обязательные характеристики Avito берутся из официальных справочников автозагрузки (store:sync-avito-dicts).\n\nЗаголовок ≤50 символов, в нём ID сборки вида DZK48190; в тексте обязательная фраза «Для получения текущего списка комплектующих для данной конфигурации (ID:…) запросите в чате».\n\nЧат: webhook POST /api/store/avito/webhook (регистрация через messenger/v3/webhook). Ключи OAuth — STORE_AVITO_* в .env (Компстор); пустые поля в админке подхватываются оттуда. Токен client_credentials живёт ~сутки, cron store:refresh-avito-token в 03:00. Аватары, фото, карточки объявлений, ссылки и голосовые: webhook/история чата кладут content в БД; UI показывает аватар, картинки (кнопка «Фото» — uploadImages + messages/image), превью объявления/ссылки, плеер голоса. Открытие чата шлёт Avito /read и подтягивает профиль + последние сообщения. Новое непрочитанное — звук во всей открытой админке. Свой рингтон: Настройки Avito → загрузка mp3/wav/ogg/m4a до 4 МБ (public disk, нужен storage:link и миграция ringtone_path); иначе /sounds/notification.mp3. Внутри «Чаты» четыре папки: общее (новые), в работу, выполнено, избранное. «В работу» назначает текущего менеджера; исходящие сообщения помечаются его именем. Новое сообщение в выполненном чате возвращает его во «в работу» (если уже был принят) или в общее.",
                        'path' => '/admin/store/avito',
                        'audience' => 'Менеджер / Система',
                    ],
                    [
                        'title' => 'Планировщик синка каталога',
                        'description' => "Laravel Schedule сам не крутится: на сервере cron пользователя www-data (тот же, что PHP-FPM):\n* * * * * cd /var/www/… && /usr/bin/php artisan schedule:run >> /dev/null 2>&1\n\nЗадачи: store:sync-supplier-catalog в 09:00 Europe/Moscow; store:classify-cases в 09:40; store:refresh-avito-token в 03:00 (OAuth ~сутки); store:sync-avito-dicts в 03:20; store:generate-avito-ads каждый час. Логи (если включены в routes/console.php): storage/logs/catalog-sync.log, catalog-cases.log, avito-token.log, avito-ads.log, avito-dicts.log.\nПроверка: sudo -u www-data php artisan schedule:list / schedule:run -v. Разовый синк: store:sync-supplier-catalog. Разовая пачка Avito: store:generate-avito-ads --sync --force.",
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
                        'description' => "Турнир Single Elimination на /admin/tournaments: создать ивент, ПК арены, призы 1/2/3 на депозит, регистрация гостей по телефону, сетка (bye до степени двойки), счёт матчей вручную, «Завершить + призы». Два проигравших полуфинала делят 3 место. Повторной выплаты нет (prizes_paid_at).\n\nПока ивент active и lock_games включён, GET /api/shell/games?terminal_id= на привязанных ПК отдаёт только игру турнира (в т.ч. полоса featured). Это не оверлей и не блокировка Win+Tab — каталог шелла.\n\nНе подключено: Swiss / Double Elim, авторезультаты Steam Web API / Valve Game Coordinator, split-check ЮKassa на компанию. Клипы — отдельная глава «Instant Replay».",
                        'path' => '/admin/tournaments',
                        'audience' => 'Supervisor+',
                    ],
                    [
                        'title' => 'Instant Replay → Reels / Shorts',
                        'description' => "Killcam-клип с ПК: шелл пишет rolling-буфер 60 с на D:/ShellData/replay, по F8 или GSI-киллу CS2 склеивает mp4 (kill — последние Replay/kill_seconds, по умолчанию 12 с, кроп 9:16 ближе к прицелу), накладывает ник, лого клуба и QR на публичную страницу клипа, грузит POST /api/shell/clips.\n\nГость видит ролик в /account/dashboard и /clips/{token} (кнопка «Забронировать ПК»). TELEGRAM_CLIPS_AUTO шлёт sendVideo в канал клуба. Личка гостя: кабинет «Привязать Telegram» → /start токена, webhook POST /api/telegram/webhook пишет users.telegram_chat_id, клип уходит в DM даже без канала.\n\nПодробности — глава «Instant Replay (клипы)» в PC Shell.",
                        'path' => '/account/dashboard',
                        'audience' => 'Shell / Игрок / Маркетинг',
                    ],
                    [
                        'title' => 'King of the Hill (трон ПК)',
                        'description' => "Битва за конкретное место: кто за сессию на этом ПК набил больше фрагов (CS2/Dota GSI), тот King дня; ничья — K/D, при 3+ фрагах лучший винрейт раундов/матчей тоже перебивает трон. Ник — GSI player.name, иначе users.name. С 3+ киллов ник и аватар на idle над QR. Чужой король — «Сможешь превзойти рекорд King?». Плитка ♔ на /admin/dashboard, owner сбрасывает POST /admin/api/computers/throne-reset.\n\nРекорд привязан к computer_id + дате (pc_thrones). Счётчик сессии в кэше 8 ч.",
                        'path' => null,
                        'audience' => 'Shell / Игрок',
                    ],
                    [
                        'title' => 'Blind Matchmaking (пати в зале)',
                        'description' => "Кнопка «ПАТИ» в шелле для соло: игра (CS2 / Dota / Valorant) и ранг. Облако ищет open-LFG в клубе с рангом ±1 и пишет «Твой тиммейт на ПК-07». Если сосед свободен — автопересадка на смежное место (новый PIN), иначе кнопка «Пересесть рядом». Войс: чат в игре или Discord клуба (CLUB_DISCORD, кнопка в попапе). Матч создаёт BookingGroup → котёл пати доступен двум соло.\n\nТаблица lan_lfg_queues, TTL 20 мин, снятие на logout.",
                        'path' => null,
                        'audience' => 'Shell / Игрок',
                    ],
                    [
                        'title' => 'Clan Wars (межзонный / межлокационный баттл)',
                        'description' => "Счёт CS2/Dota GSI в реальном времени между сторонами зала или локациями сети. Не турнир Single Elim (это «Менеджер ивентов») и не King of the Hill (трон одного ПК).\n\nРежимы: зоны одной локации — Bootcamp vs Standard; сеть — Club A vs Club B. Сайдбар Киберспорт → /admin/clan-wars: создать, «В эфир», завершить. Одна live-война, длительность 10–240 мин (по умолчанию 60), игра any / cs2 / dota. match_win = 10 очков + победа, round_win = 1. По окончании Elo фракции (старт 1000) и личный вклад в ЛК.\n\nСчёт на TV/PC lobby (оверлеи DAT mid_left/mid_right) и в блоке Clan Wars кабинета игрока.\nПодробности — глава Shell «Clan Wars».",
                        'path' => '/admin/clan-wars',
                        'audience' => 'Supervisor+ / Shell / Игрок',
                    ],
                    [
                        'title' => 'Lucky Seat Lootbox (дроп за стрик)',
                        'description' => "Кейс прямо в шелле: серия побед GSI (2 матча подряд или 5 раундов CS2) или каждые 3 часа активной игры. Кулдаун 3 ч на игрока, один неоткрытый кейс за сессию.\n\nНаграда: бонус 50/75/100 ₽ (~55%), напиток бара со слипом (~30%; нет на складе → бонус), или промокод RX-***** 10% периферии REACTOR Store на 30 дней (~15%). Не путать с клубными промокодами /admin/promocodes.\n\nНеоткрытый кейс на logout открывается сам. Код в магазине — поле при создании заказа /admin/store/orders.\nПодробности — глава Shell «Lucky Seat Lootbox».",
                        'path' => '/admin/store/orders',
                        'audience' => 'Shell / Игрок / Магазин',
                    ],
                    [
                        'title' => 'Маркетинг (промокоды)',
                        'description' => 'Клубные промокоды /admin/promocodes: бонусные деньги или скидка, лимит активаций. Игрок применяет код в кабинете.\n\nНе путать с одноразовыми RX-***** Lucky Seat (скидка 10% на периферию в заказе магазина, таблица store_promo_codes, телефон владельца). Их выдаёт шелл, а не эта страница.',
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
                        'description' => "ИП, УСН «доходы» 6%, есть наёмные, плюс НДС.\n\nБаза УСН — кассовые пополнения с фискальным чеком success (карта/СБП/касса), без бонусов, без возвратов на кошелёк и без демо-заглушек (/receipt/stub, fiscal_status=skipped), пока касса выключена. НДС выделяется из чека (B2C); из базы УСН исключается. 2026: освобождение при выручке прошлого года до 20 млн; иначе спецставка 5% (до 272,5 млн) или 7% (до 490,5 млн), входной НДС не вычитается.\n\nАвансы УСН нарастающим итогом. Сотрудники по ТК: вычет взносов (фикс ИП + 1% + единый тариф 30/15,1% + травматизм 0,2%) не больше 50% налога. НДФЛ 13–22% — агентский, в вычет УСН не идёт. Неофициальные ставки в расчёт не попадают.\n\nКалендарь уплаты: УСН и фикс/1% ИП, НДС тремя платежами 28-го, взносы и НДФЛ за штат 28-го следующего месяца, травматизм 15-го в СФР. Выходной сдвигается на понедельник.\n\nКУДиР: кнопка на /admin/taxes → /admin/taxes/kudir (печать / «Сохранить как PDF»). Внутренний регистр доходов по фискализированным пополнениям, не бланк Минфина.",
                        'path' => '/admin/taxes',
                        'audience' => 'Owner',
                    ],
                    [
                        'title' => 'Штат',
                        'description' => "Список сотрудников и найм владельцем/управляющим (в т.ч. сразу сборщик / менеджер / старший с ставкой и pay_type, без анкеты).\n\nСаморегистрация клуба — /admin/login; магазина — /store/login (должность сборщик или менеджер). Общая вкладка «На проверке»: назначить дату визита или отклонить; после даты кнопка «Биометрия» (заглушка камеры). Кандидат клуба принимает ПБ в /admin/salary, кандидат магазина — на /store/hire. Роль магазина при приёме сохраняется.\n\n«Уволить» закрывает вход, карточку не удаляет. Уволенный магазинный сотрудник при логине уходит на /store/login.",
                        'path' => '/admin/staff',
                        'audience' => 'Supervisor+',
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
                        'title' => 'Конфигурация смен',
                        'description' => 'Схема рабочих слотов локации: 12 часов (день/ночь) или 24 часа, час начала (10:00, 11:00, 12:00…). Конец считается автоматически. Уже выбранные смены сотрудников сохраняются, свободные слоты другой схемы снимаются. Админ зала занимает lead, стажёр — intern, магазин — kind=store (не блокирует lead). Доступ: владелец и управляющий.',
                        'path' => '/admin/config',
                        'audience' => 'Supervisor+',
                    ],
                    [
                        'title' => 'Документы устройства',
                        'description' => 'Пункт «Документы» в конфигурации (/admin/config/documents). Системные: «Условия работы администратора» (при анкете) и «Техника пожарной безопасности» (после биометрии). Одни тексты для зала (/admin/salary) и магазина (/store/hire). Каждый документ — набор разделов (заголовок + текст). «Добавить» создаёт раздел. На сайте разделы свёрнуты; клик по заголовку раскрывает один блок, внутри — «Принимаю».',
                        'path' => '/admin/config/documents',
                        'audience' => 'Supervisor+',
                    ],
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
                        'description' => "Экранные блоки терминала (картинка / видео / текст) на /admin/overlays. Shell забирает активные оверлеи по позициям GET /api/shell/overlays.\n\nПока Clan War live, в тот же JSON добавляется clan_war, а слоты DAT mid_left / mid_right рисуют счёт сторон (роль clan_war). Idle TV поллит оверлеи ~4 с вместо 45 с. Не путать с ручными текстовыми слоями — live-счёт пишет ClanWarService::paintOverlayBlocks.",
                        'path' => '/admin/overlays',
                        'audience' => 'Supervisor+ / Shell',
                    ],
                    [
                        'title' => 'Игры и лицензии',
                        'description' => "Каталог игр, Steam/игровые аккаунты, офферы клуба (free / per_seat_hour и др.). Shell: take/free аккаунта, запись запуска, обновление VDF-кэша на станции (machine cache, pivot аккаунт×ПК).\n\nЭто облачный пул лицензий для уже установленного софта на диске/образе ПК — не Steam Caching / Game Center и не смена VHD с кнопки «игры».\n\nШелл в heartbeat сканирует Steam appmanifest_*.acf и Epic .item на D: и присылает compact list (appid/build/name). Совпадение названия с каталогом поднимает computer_games.verified_at, is_installed не сбрасывается. На дашборде — счётчики steam/epic по месту.\n\nИнтеграций CCBoot / SENET Boot / NDEV / iCafemenu как PXE-контроллера нет. Super Client из админки оркестрирует уже существующий CCBoot Client на ПК (очередь heartbeat), не API сервера бездиска.",
                        'path' => '/admin/licenses',
                        'audience' => 'Supervisor+ / Shell',
                    ],
                    [
                        'title' => 'Питание ПК: WOL и выключение',
                        'description' => "Уже есть (без IPMI / Smart PDU):\n\n• ComputerPowerService: desired on/off по активным и ближайшим броням (warmup CLUB_POWER_WARMUP_MINUTES, по умолчанию 30 мин до старта). Техрежим (computers.maintenance / status=maintenance) держит desired=on и power_action=none — idle не гасит место.\n• Онлайн = свежий heartbeat шелла (last_seen_at; stale CLUB_POWER_HEARTBEAT_STALE_SECONDS).\n• Состояния: on / off / booting / error (таймаут WOL или нет MAC). Плитки «сервис» и «кэш» — техрежим и cache_ok=false.\n• Wake-on-LAN: облако само magic packet в LAN не шлёт. MikroTik (или LAN-агент) pull’ит очередь GET /api/power/wol-targets?token=… и подтверждает POST /api/power/wol-sent (токен CLUB_WOL_RELAY_TOKEN). MAC приходит с шелла в /api/shell/power/heartbeat.\n• После logout / idle при desired=off сервер отдаёт power_action=shutdown|reboot; Shell (Qt) делает S5 (shutdown /s или /r, не Sleep) с flush SSD.\n• Heartbeat дополнительно: cache_ok, cache_free_gb, data_root, volume_letter, maintenance, nic_link_mbps, SMART SSD (wear/health/errors), super_client, Steam/Epic-манифесты с D:.\n• Дашборд: снимок питания ПК, в т.ч. «Ошибка WOL», «кэш SSD мёртв», «обслуживание», Super Client, линк ≤100 Мбит, износ SSD.\n• Super Client из админки: POST /admin/api/computers/diskless → шелл читает diskless в ответе heartbeat. Пароль не в облаке.\n• Cron reactor:update-statuses пересчитывает desired/state вместе со статусами сессий.\n\nНет: IPMI, Smart PDU, кнопка «принудительно выключить/перезагрузить» из админки поверх расписания, агент на сервере CCBoot (restore/refresh cache). LAN P2P патчей есть — глава Shell «LAN P2P-кэшер патчей» (Super Client или ночной fallback-сид на самом быстром D:). Link flap — «Деградация кабеля».",
                        'path' => '/admin/dashboard',
                        'audience' => 'Supervisor+ / Shell / MikroTik',
                    ],
                    [
                        'title' => 'Diskless / кэш игр — статус',
                        'description' => "Правка золотого образа — с игрового ПК (Shell setup) или очередью из админки: POST /admin/api/computers/diskless → шелл в ответе heartbeat включает CCBoot Client. Пароль — Diskless/admin_password в config.ini на месте, не в облаке. Один Super Client на клуб; гость на месте блокирует команду; диск image по умолчанию, game/both — отдельное подтверждение.\n\nПошагово локально: «Обслуживание образа: setup и Super Client» в разделе Shell.\n\nНе реализовано: агент на сервере .10, restore points / refresh cache кнопкой, смена VHD, BitTorrent.\nЕсть LAN P2P-кэшер патчей — полная глава в Shell: «LAN P2P-кэшер патчей (Steam/Epic)» (seed :8745, peer patch_pull по buildId без WAN).\n\nСеть под бездиск — «Сеть клуба»: VLAN 20 общий L2 сервер+ПК, 2×10G LACP на CRS354, DHCP только один источник. Админка оркестрирует лицензии/WOL/Super Client на клиенте, не PXE.",
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
                        'description' => "Zabbix-замены нет: не ping WAN, не CPU/RAM хоста booking, не баланс SMS-шлюза, не uptime W5100. Канал — dual-WAN / LTE на MikroTik.\n\nРучные проверки владельца: /admin/system-tests (кнопки контуров + PHPUnit). Это не мониторинг 24/7.\n\nУже есть по контурам:\n• ПК / Shell online — power heartbeat (~30 с), last_seen_at, stale по CLUB_POWER_HEARTBEAT_STALE_SECONDS; снимок питания и ошибки WOL на дашборде.\n• Линк NIC и SMART кэша — nic_link_mbps (алерт ≤100 Мбит), nic_flap_count (≥2 за смену → инцидент патч-корд; глава Shell «Деградация кабеля»), ssd_wear_pct / ssd_health / ошибки R/W, температура SSD как раньше.\n• Инвентарь игр с D: — счётчики Steam/Epic; LAN patch seed/pull — глава Shell «LAN P2P-кэшер патчей».\n• Super Client — плитка violet, очередь с дашборда.\n• Сессия на шелле — poll balance/heartbeat (~8 с); падение session_active / logout закрывает UI.\n• Вентиляция — desired на сервере, apply→ack с Shell; thermal CPU°C с ПК для авто-скорости; shared-реле через LAN-агент. Отдельного «W5100 не пингуется» инцидента нет: смотрим, что шелл/агент живы и applied доходит.\n• Качество сервиса — reactor:check-quality: late_order, low_stock → /admin/incidents.\n• SOS / HID / вызов админа — лента инцидентов и бейджи сайдбара. Hardware Health (дребезг мыши / залипание клавиши) — тикет «Проверить свитч/микрик на ПК-ХХ», глава Shell.\n• Автозакрытие сессий / питание — reactor:update-statuses каждую минуту.\n\nНет в продукте: ICMP ping провайдера, CPU/RAM хоста booking, опрос баланса SMS-шлюза, watchdog desired≠applied по W5100. WAN/failover — на стороне MikroTik или внешнего Uptime; SMS-баланс — когда шлюз боевой (сейчас SMS-вход ещё тестовый код в логах).",
                        'path' => '/admin/system-tests',
                        'audience' => 'Owner / Техник',
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
                        'description' => "Личный вентилятор места (SpaceFan) сидит на HW-584 (RelayBoard, driver netmod_http): host + TCP-порт (по умолчанию 8080). URL команды: http://{host}:{port}/{cmd} (NetMod-ServerApp). Старый заводской W5100 (w5100_http) — path-порт: http://{host}/{port}/{cmd} на TCP :80.\n\nПины в веб-морде NetMod должны быть Output, иначе /00–/31 молчат. Веб-морда: :8080, не :80.\n\nДва канала каскада K1+K2 (пары 1+2, 3+4 … 15+16):\n• скорость 1 (night / 120V) — K1 OFF, K2 OFF\n• скорость 2 (mid / 170V) — K1 ON, K2 OFF\n• скорость 3 (high / 220V) — K1 OFF, K2 ON\n\nПолного электрического OFF на двух CO-реле нет: «выкл» = night. Прыжок 1↔3 идёт через mid ~2.5 с, чтобы не бить контакторы. На комнату (space) до 2 личных вентиляторов.",
                        'path' => '/admin/fans',
                        'audience' => 'Supervisor+ / Shell',
                    ],
                    [
                        'title' => 'Вентиляция: кто крутит реле',
                        'description' => "Облако (booking) только считает desired_power и факты (сессия / CPU°C / manual). Физический HTTP на NetMod/W5100 делает Shell по LAN — сервер в интернет до платы не ходит.\n\nPC Shell (Qt): опрос fan state, ручные 50/75/100%, thermal report, apply → ack.\nTV Shell (APK): привязка пары каналов в Setup (discover → ТЕСТ high ~2.5с → ПРИВЯЗАТЬ), те же API /api/shell/fan/*.\n\nРежимы: auto (по сессии и термопорогам), force_on, force_off(=night). Пороги thermal_on_c / thermal_off_c (дефолт 75 / 65). Пустая комната сбрасывает force_on в auto.",
                        'path' => '/admin/fans',
                        'audience' => 'Supervisor+ / Shell / TV',
                    ],
                    [
                        'title' => 'Вентиляция: общие приток/вытяжка',
                        'description' => "SharedFan (supply / exhaust) — общие вентиляторы клуба. К ним мапятся личные SpaceFan; нагрузка пересчитывается (SharedFanControlService) от desired_power мест.\n\nАктуация shared-реле — агент в LAN по токену FAN_SHARED_RELAY_TOKEN (или CLUB_WOL_RELAY_TOKEN). Админка: платы, личные вентиляторы, shared, карты привязок.",
                        'path' => '/admin/fans',
                        'audience' => 'Supervisor+ / Система',
                    ],
                    [
                        'title' => 'Свет DMX: железо и Art-Net',
                        'description' => "Админка /admin/lights (Конфигурация → Свет DMX), вкладка «Узлы и комнаты». Свет комнаты (SpaceLight) сидит на Art-Net узле (DmxNode): host + UDP-порт (6454, LIGHT_ARTNET_PORT) + universe. Облако desired в контроллер не шлёт — пакеты ArtDmx шлёт PC Shell по LAN, как W5100 для вентиляторов.\n\nРаскладки прибора от start_channel (1-based):\n• rgb — R G B, яркость масштабирует RGB\n• dimmer_rgb — dimmer R G B\n• rgbw — R G B W (белый идёт в W)\n\nНесколько приборов подряд: fixture_count. На комнату один SpaceLight; все ПК комнаты видят одно состояние. Universe лучше разносить по комнатам; если один universe на узел — шелл собирает все приборы узла в один кадр, чтобы не гасить соседние комнаты нулями.\n\nКлуб в селекте по умолчанию — текущая локация (AdminLocation), не первый клуб в алфавите.",
                        'path' => '/admin/lights',
                        'audience' => 'Supervisor+ / Shell',
                    ],
                    [
                        'title' => 'Свет DMX: кто шлёт пакеты',
                        'description' => "Плитка в шелле: кружки белый / красный / синий / зелёный / жёлтый / фиолетовый / rainbow (HSV по wall-clock, период LIGHT_RAINBOW_PERIOD_MS, дефолт 8 с) + ползунок яркости 0–100%. Ручной кулдаун LIGHT_MANUAL_COOLDOWN_SEC (2 с) — соседний ПК комнаты не перебивает цвет сразу.\n\nОблако хранит desired (цвет/яркость/эффект комнаты). Шелл apply → POST /api/shell/light/applied. GET/POST /api/shell/light; heartbeat и power/offline тоже отдают light (в т.ч. events). Комната одна на SpaceLight — кто последний пакет Art-Net, тот и красит.\n\nГалка «интерактив» в шелле (user_settings.light_interactive, POST /api/shell/light/interactive) включает локальный приём игр. Без галки события питания/сессии всё равно отрабатывают по вкладке «Интерактивный свет».",
                        'path' => '/admin/lights',
                        'audience' => 'Supervisor+ / Shell',
                    ],
                    [
                        'title' => 'Свет DMX: интерактивный свет (события)',
                        'description' => "Вкладка /admin/lights?tab=interactive. На клуб (club_light_settings.events). Пока ни разу не жали «Сохранить события» — в API light.events_from=presets (вшитые дефолты). После сохранения — events_from=admin, шелл красит по админке.\n\nКаталог уходит в каждом light.events (даже если комната ещё не привязана к узлу). Шелл подхватывает с heartbeat / логина / GET /api/shell/light.\n\nПоля каждого события:\n• включено — выкл = не играть overlay (для питания цвет покоя всё равно берётся из события)\n• длительность, с — 0 = держать до следующего события\n• цвет — white/red/blue/green/yellow/purple/orange/cold_white; радуга; hex #RRGGBB; у Chroma/GameSense ambient ещё «авто» (цвет из игры)\n• эффект — цвет / радуга / смена цветов\n• яркость 0–100%\n• стробоскоп — длительность вкл и выкл, мс\n• смена цветов — какие цвета чередовать (до 8) и сколько секунд светить каждым\n• плавность fade, с (0–30)\n\nДлительность 0 + строб/смена на «открытие сессии» держит overlay поверх цвета игрока, пока не придёт следующее событие. Чтобы вспышка и возврат к цвету игрока — поставьте длительность > 0.",
                        'path' => '/admin/lights',
                        'audience' => 'Supervisor+ / Shell',
                    ],
                    [
                        'title' => 'Свет DMX: события питания и сессии',
                        'description' => "Свет живёт от питания ПК (heartbeat / power_state), не от пустой брони. Сцены комнаты: off / idle / session. Смена сцены даёт light.play_event (разовый overlay в шелле), затем desired из события покоя.\n\n• pc_on «включение компьютера» — любой ПК комнаты on/booting, сессии нет. Дефолт: белый, яркость LIGHT_DEFAULT_BRIGHTNESS (80), fade LIGHT_FADE_IDLE_MS (1.2 с), длительность 0.\n• session_start «открытие сессии» — PIN/QR логин. Overlay по настройкам события; desired = цвет игрока (user_settings). Первый визит (нет сохранённого цвета) берёт цвет/яркость этого события (дефолт зелёный, fade LIGHT_FADE_LOGIN_MS 2.5 с).\n• session_end «конец сессии» — логаут при живых ПК. Overlay, затем снова pc_on (лобби), не off.\n• pc_shutdown «выключение компьютера» — последний ПК комнаты гаснет. Дефолт длительность = LIGHT_FADE_OFF_MS (0.8 с).\n• pc_off «компьютер выключен» — все ПК off/stale. Дефолт яркость 0, держать.\n\nplay_event шлётся только на переходе сцены, не на каждом heartbeat. Строб/смена на idle после рестарта шелла вернутся при следующем включении/логине; цвет покоя приходит сразу в desired.",
                        'path' => '/admin/lights',
                        'audience' => 'Supervisor+ / Shell',
                    ],
                    [
                        'title' => 'Свет DMX: игры (GSI, Chroma, GameSense)',
                        'description' => "Нужна галка «интерактив» в шелле и активная сессия. Игры кормят шелл локально (не через облако). Цвета/строб/длительность — из вкладки «Интерактивный свет», не из хардкода (хардкод шелла — только пока events ещё не пришли).\n\nCS2 / Dota 2 GSI → 127.0.0.1:59898, файл gamestate_integration_reactor.cfg (токен reactor-club). В cfg ещё player_position — координаты для зон карты. События:\n• cs2.bomb — planted, дефолт красный строб 90/90 мс, держать до диффуза/взрыва/конца раунда\n• cs2.win — phase=over + win_team, дефолт синий 2.5 с\n• cs2.death — игрок мёртв в раунде, дефолт белый 12%\n• cs2.ambient.winter / cs2.ambient.inferno / cs2.flash — Game-Sense Ambient DMX Mirroring (отдельная глава)\n• dota.win / dota.death — то же по смыслу (победа / смерть героя)\n\nRazer Chroma REST 127.0.0.1:54235 (без Synapse). Событие chroma, цвет «авто» = приборы из игры; фиксированный цвет в админке перекрывает игру.\n\nSteelSeries GameSense: шелл пишет %PROGRAMDATA%/SteelSeries/.../coreProps.json и слушает JSON.\n• gamesense.bomb / .win / .death / .hit — те же дефолты, что CS2 (hit — красный 0.4 с)\n• gamesense — ambient цвет кадра, «авто» или фикс\n\nПриоритет overlay: слепота/бомба (Alert) > раунд/победа > смерть/удар > зима/огонь ambient > chroma/gamesense ambient > desired комнаты.",
                        'path' => '/admin/lights',
                        'audience' => 'Supervisor+ / Shell',
                    ],
                    [
                        'title' => 'Game-Sense Ambient DMX Mirroring',
                        'description' => "Расширение световых профилей Art-Net. Личный светильник стола (SpaceLight комнаты/места, пакеты шлёт PC Shell этого ПК) меняет цветовую температуру под игровое окружение CS2. Нужна галка «интерактив». Цвета и длительности — вкладка /admin/lights?tab=interactive, события cs2.ambient.winter / cs2.ambient.inferno / cs2.flash.\n\nЗима (cs2.ambient.winter). Холодный белый (cold_white ≈ RGB 200 220 255), держать, fade 0.8 с. Карты: de_nuke (вся); de_ancient снаружи. «Снаружи» — GSI player.position: не пещеры/B (AABB пещер B: x −400…1650, y −2200…−80; mid-cave: x −650…250, y −450…180, z < 140). Нет координат — Ancient целиком как зима (руины).\n\nОгонь (cs2.ambient.inferno). Мягкий оранжевый (orange ≈ RGB 255 138 60), держать, fade 0.5 с. Карта de_inferno целиком или player.state.burning ≥ 20 (molotov) на любой карте — огонь перекрывает зиму.\n\nBlind (cs2.flash). Попадание светошумовой: player.state.flashed ≥ 80 (0–255), фронт или скачок +40. Вспышка 100% белого на 0.8 с, fade 0. Пока flashed < 40 — можно вспыхнуть снова. Приоритет Alert: на 0.8 с перекрывает бомбу/смерть, потом слой снимается.\n\nСлои держатся, пока матч live/freezetime/over/warmup. Выход с карты снимает ambient. Бомба/раунд/смерть по-прежнему сверху зимы/огня. Код: ValveGsi + ReactiveLighting (shell), LightEventCatalog (booking).",
                        'path' => '/admin/lights',
                        'audience' => 'Supervisor+ / Shell',
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
                        'description' => "Закладка на записи NVR, чтобы не мотать 40 каналов: HID (мышь/клава) или SOS → флажок с текстом «HID · PC-08». Это не иконка лица/машины и не «Событие AIOP».\n\nИскать: Воспроизведение → камера (канал из админки, 1 = D1) → шкала; либо бэкап/поиск по тегу.\n\nТриггеры: hid.disconnected / hid.device_changed / hid.unstable (POST /api/shell/hid/alert), sos (POST /api/shell/sos), store.assembly_start / store.assembly_done (магазин, камера стола сборщика), тест в админке. События — /admin/video-surveillance. Канал = номер камеры NVR (1 → track 101). ПК зала→камера пока нет; стол сборки — STORE_ASSEMBLY_NVR_CHANNEL или канал события.",
                        'path' => '/admin/video-surveillance',
                        'audience' => 'Supervisor+ / Админ / Техник',
                    ],
                    [
                        'title' => 'Видео-метки: агент на ПК лиц',
                        'description' => "Облако на NVR не ходит. Очередь video_surveillance_marker_jobs → агент scripts/hikvision-marker-agent.ps1 на ПК лиц (VLAN 20 + доступ к 192.168.222.12). Не бездиск, не RB5009.\n\nАгент: GET /api/video/marker-targets?token=… → Digest PUT …/recordTag + lock → POST /api/video/marker-applied. Тот же процесс: GET /api/video/assembly-clip-targets → ffmpeg RTSP playback стола сборщика → POST /api/video/assembly-clips (нужен ffmpeg в PATH). Env: VIDEO_API_BASE, VIDEO_MARKER_TOKEN (= VIDEO_MARKER_RELAY_TOKEN или CLUB_WOL_RELAY_TOKEN). Планировщик Windows, автозагрузка.\n\nАдминка: вкл, провайдер Hikvision NVR, URL http://192.168.222.12, admin + пароль NVR, канал стола сборки, path пустой. События «мышь», «SOS», шаблоны «сборка ПК» / «ПК готов». Тест без агента только копит очередь.\n\nHik-Connect, ISUP (Ehome), OTAP, HEOP/AIOP — не включать, к меткам не относятся. NVR в интернет не пробрасывать.",
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
                        'description' => "Экран LoginActivity: телефон + PIN, экранная цифровая клавиатура (системная IME выключена).\n\nОверлеи: 6 слотов left/right (top/mid/bottom) из GET /api/shell/overlays. Слои image / video / text. Видео — ExoPlayer + SurfaceView, старт со сдвигом 0…1250 мс, mute, loop. На onPause / уходе с idle — полный release плееров (не просто hide).\n\nLive Clan War: в том же JSON ключ clan_war + текстовые слои DAT mid_left/mid_right (счёт сторон). Пока война live, idle-полл оверлеев ~4 с вместо 45 с.\n\nСеть: SessionNetworkPolicy → ui-state session_idle → очередь isolate на MikroTik (MAC с терминала).",
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
                        'description' => "Единая лента /admin/incidents: late_order, low_stock, расхождения склада, SOS, HID, ручные записи, shell-инциденты (fan_bearing_wear, golden_image_drift, nic_link_flap — «Заменить патч-корд на ПК-ХХ», hardware_switch_fault — «Проверить свитч/микрик на ПК-ХХ»).\n\nТипы с ПК: POST /api/shell/incidents (ShellIncidentService, dedupe по computer_id+type пока не закрыт). Link flap ≥2 за смену пишет nic_link_flap из heartbeat (см. «Деградация кабеля» в Shell). Дребезг мыши / залипание клавиши — Hardware Health поверх HID-сессии (глава Shell).\n\nAck и закрытие (resolve — supervisor+).",
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
                        'description' => 'Снимки периферии и алерты: смена / отключение / нестабильность устройств. Триггеры hid.disconnected / hid.device_changed / hid.unstable опционально ставят видео-метки на NVR (события в /admin/video-surveillance).\n\nПоверх той же HID-сессии шелл (HardwareHealthWatchdog) смотрит физические события ввода: дребезг микрика мыши (фантомный дабл-клик) и залипание/дребезг скан-кода. Это не computer_input_alerts, а тикет hardware_switch_fault «Проверить свитч/микрик на ПК-ХХ» в этой ленте.',
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
                        'description' => "Баланс, активные брони с таймером, заказы магазина, транзакции, прогресс достижений, статус заявки на бонус за отзыв.\n\nБлок Clan Wars (если есть live или рейтинг): текущий счёт сторон, свой Elo по фракциям, таблица кланов. Подробности — «Clan Wars» в Киберспорте / Shell.\n\nВ шапке на мобильных/планшетах — иконка QR-сканера (на десктопе скрыта: вход с телефона). См. «Вход по QR».\n\nКнопка «Сесть за ПК» без живой сессии — заготовка быстрого входа (логика «Подключиться» ещё заглушка). При активной сессии кнопка становится «Пересесть» (см. «Пересадка на другой ПК»).",
                        'path' => '/account/dashboard',
                        'audience' => 'Игрок',
                    ],
                    [
                        'title' => 'Сканер QR (вход на ПК)',
                        'description' => "Иконка QR в навигации ЛК (только mobile/tablet, lg скрыта) на любой странице с MainLayout. Камера телефона читает QR с экрана idle PC Shell (jsQR).\n\nPOST /account/qr/redeem {token, accept_seat_change?}:\n• есть бронь на этот ПК (confirmed/paid/active) → та же активация, что по PIN, challenge → consumed, шелл подхватывает вход;\n• бронь на другом ПК, этот занят на окно сессии → wrong_pc_occupied;\n• бронь на другом ПК, этот свободен → wrong_pc_available, Да переносит бронь (accept_seat_change) и открывает сессию;\n• ПК свободен, брони нет → needs_booking: выбор длительности от 60 мин шагом ±15, quote/book с баланса; не хватает денег → пополнение (Reactor Pay) и повтор «Открыть сессию»;\n• ПК занят чужой сессией (брони у гостя нет) → occupied.\n\nPayload QR: {APP_URL}/account/dashboard?qr={uuid} — по ссылке сканер открывается сам. TTL challenge 120 с (таблица shell_qr_challenges).",
                        'path' => '/account/dashboard',
                        'audience' => 'Игрок',
                    ],
                    [
                        'title' => 'Настройки профиля',
                        'description' => 'Редактирование никнейма и email. Телефон зафиксирован как идентификатор входа. Стартовый ник при первой регистрации выдаёт DeepSeek (см. «Игровой ник»).',
                        'path' => '/account/profile',
                        'audience' => 'Игрок',
                    ],
                    [
                        'title' => 'Игровой ник',
                        'description' => "Гость ник не выбирает: при первом SMS-входе (POST /auth/verify-code, новый users.phone) PlayerNicknameService спрашивает DeepSeek — тот же ключ, что у голосового компаньона (админка /admin/ai-assistant или DEEPSEEK_API_KEY).\n\nПромпт: произносимый игровой позывной, CamelCase из одного-двух коротких слов, только буквы (латиница или кириллица), 4–12 символов, без пробелов, цифр, подчёркиваний _ и дефисов -. Банальности Gamer / Player / User / Stalker отбрасываются. Ответ чистится (Frost-Fox_ → FrostFox). Таймаут 8 с, thinking у V4 выключен.\n\nЕсли ключа нет, LLM упал или ник уже занят — слово из запасного списка (Nova, Drift, Raven…); при исчерпании пула — то же слово + число без разделителя (Nova7). Повторный вход по телефону имя не меняет.\n\nСмена: /account/profile (users.name, max 50). Ник уходит в шелл (login name) и в промпт голосового приветствия {{player}}.",
                        'path' => '/account/profile',
                        'audience' => 'Игрок / Система',
                    ],
                    [
                        'title' => 'Магазин',
                        'description' => "Витрина бара/кухни для игрока (/shop). Списание с депозита. Не путать с магазином ПК для сотрудников (/store/login, раздел «Магазин компьютеров»).\n\nС живой сессией — доставка сразу к текущему ПК (следует за computer_id после пересадки).\nБез сессии, но с оплаченной бронью — заказ принимается (status=scheduled); бар не видит задание, пока не наступит окно за 7 мин до старта (reactor:update-statuses) или гость в шелле не нажмёт «Я на месте — несите заказ». Клиенту: доставка за 5 мин до сессии к забронированному ПК. После ACCESS GRANTED — попап «напитки и перекус» → /shop.\nБез сессии и без брони — отказ.",
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
                        'description' => 'Публичная карта клуба, зоны, тарифы, availability ПК и игр, расчёт цены, бронь с PIN. Есть киоск `/terminal`.\n\nПати: в сайдбаре «Сесть рядом» выбирает N свободных мест подряд в одной зоне (type + space_id, по номеру в имени ПК). Оплата как у обычной мультиброни (один BookingGroup, один PIN) — отдельной ссылки сплит-чека нет.',
                        'path' => '/booking',
                        'audience' => 'Гость / Игрок',
                    ],
                    [
                        'title' => 'SMS-вход',
                        'description' => 'Вход по телефону: send-code / verify-code. Новому игроку ник придумывает DeepSeek (см. «Игровой ник»). Реальная SMS пока не подключена (тестовый код 0451 в логах). При входе пишется users.offer_accepted_at.',
                        'path' => '/login',
                        'audience' => 'Игрок',
                    ],
                    [
                        'title' => 'Клиентское приложение (0451)',
                        'description' => "Android-обёртка сайта клуба, не путать с PC Qt-шеллом и TV Shell (ru.compclub.tvshell).\n\nПакет space.club0451.client, имя на устройстве «0451», исходники android-client/. WebView открывает CLUB_URL: лендинг, бронь, кабинет, магазин гостя, QR-вход на ПК. UA дополняется CompClubClient/… — по нему сайт прячет кнопку «Скачать приложение», заказы бара пишутся channel=app, админка и портал магазина режутся (middleware BlockAdminInClientApp + редирект /admin и /store → / в WebView и в app.js; в бандл приложения Admin-страницы, StoreLogin и StoreHire не входят).\n\nСкачать с сайта: GET /app.apk (файл storage/app/apk/sector0451.apk), кнопка в шапке (только вне приложения). Самообновление: GET /app.json {version_code, version_name, apk_url, size}; если version_code больше установленного — DownloadManager и установка. После выкладки APK поднять CLIENT_APP_VERSION_CODE / CLIENT_APP_VERSION_NAME в .env (config/client_app.php).\n\nКамера — сканер QR в ЛК (jsQR). Микрофон — на будущее под голосовые фичи сайта. Не ставить как киоск на ТВ.",
                        'path' => '/app.apk',
                        'audience' => 'Игрок / Техник',
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
                        'description' => "Зачем. Гибрид: один Windows-образ на все игровые ПК + SSD/NVMe в каждом как кэш. Обычная загрузка пишет изменения гостя в writeback. После reboot writeback выкидывается — Windows, драйверы, сам Shell, лаунчеры на C: как были в образе. Без Super Client патч Valorant, обновление шелла или драйвер GPU на одном ПК до следующего ребута живёт и на остальные 39 не попадает.\n\nSuper Client (CCBoot / CCBoot Cloud) говорит серверу: этот ПК сейчас редактирует выбранный диск. Пока режим включён, запись идёт в образ (или игровой диск), а не в одноразовый writeback. Выключили Super Client и сохранили — золотой образ обновлён, остальные ПК получат его с кэша/сервера.\n\nПочему не только из booking. Пароль Admin Password CCBoot живёт на клиенте (config.ini Diskless/admin_password) или вводится в setup. Облако очередь команд, не PXE и не хранилище пароля. Без пароля в ini удалённая кнопка вернёт no_password.\n\nУдалённо с дашборда. Клик по месту → Super Client (диск image/disk/both) или «Выкл + save». POST /admin/api/computers/diskless. Шелл забирает diskless из ответа /api/shell/power/heartbeat (~30 с), ставит maintenance, жмёт CCBoot Client. Гарды: ПК онлайн, нет active-сессии, не второй Super Client в клубе. Game disk — два подтверждения: том лочится.\n\nПочему из шелла, а не из booking. Админка лицензий/WOL образом не управляет (нет API CCBoot в облаке). Техник стоит у места: Win+ПКМ → setup → включить Super Client на этом клиенте. Пароль — Admin Password CCBoot (General Options на сервере бездиска), не PIN брони и не пароль кассы.\n\nКакой диск. В setup: image | disk | both.\n• image — ОС, Shell, Visual C++, лаунчеры, античиты в Program Files на системном томе. Так делают почти всегда.\n• disk — игровой том (Steam library и т.п.). Youngzsoft не советует держать Super Client на game disk у всех ПК: том лочится на сервере, остальные грузят игры медленнее, риск порчи. Игры лучше катить с сервера или с локального SSD-кэша без SC на game disk.\n• both — только если сознательно правите и ОС, и игровой диск с одного клиента.\nПо умолчанию: image.\n\nКогда нельзя. Час пик зала (остальные грузятся дольше). Super Client уже висит на другом ПК с тем же образом. Мало места на image disk сервера (нужен запас 10–20 ГБ). Сессия гостя на этом месте — сначала logout. Не обновлять драйвер LAN/NIC в Super Client — сломает PXE/iSCSI.\n\nЧто должно быть в образе заранее. C:\\CCBootClient\\CCBootClient.exe (или путь в config.ini [Diskless] client_exe=). [Security] production=true. iCafeMenu / iCafeCloud cafe-оболочку не ставить — шелл клуба это REACTOR. [Diskless] server_ip=192.168.20.10 справочно.\n\nАлгоритм включить и править\n1) ПК в idle: экран логина GUEST, гостя нет. Сессию закрыть.\n2) Открыть setup (REACTOR CONTROL), не путать с паузой и reboot-PIN гостя:\n   • Win+ПКМ на экране логина;\n   • если киоск съел Win (NoWindowsKey) — Ctrl+ПКМ или Ctrl+клик по имени ПК (TERMINAL_ID).\n3) Блок «ОБРАЗ · SUPER CLIENT». Поле пароля = Admin Password CCBoot. Диск = image.\n4) «Включить Super Client». Шелл снимает киоск (unlockSystem + explorer), прячет себя, запускает CCBootClient.exe и жмёт Enable Super Client / тип диска / пароль / reboot. Если диалоги не поймались — «Только CCBoot Client» и те же кнопки руками в окне Youngzsoft.\n5) ПК уходит в reboot. Это норма: Super Client применяется со следующей загрузки.\n6) После reboot шелл видит флаг Super Client и киоск не ставит (explorer доступен). Снова Win+ПКМ в setup, если нужен UI шелла; либо работайте с рабочего стола.\n7) Правки: Windows Update, GPU-драйвер (не LAN), новый билд шелла, лаунчер, античит в образ, программы в Program Files. Игры, которые должны жить на SSD/игровом томе, не тащите на C: без нужды.\n8) Проверка на ЭТОМ ПК: игра/шелл стартуют. Не выключайте ПК кнопкой питания в середине записи образа.\n\nАлгоритм сохранить и выйти\n9) Снова setup → «Выключить и сохранить» (Disable Super Client + save). Пароль тот же. CCBoot спросит: сохранить образ? Да. Restore point? Да, с короткой пометкой (дата + что меняли). Затем shutdown клиента.\n10) На сервере бездиска / в iCafeCloud PC больше не красный Super Client. Образ записан. Остальные места: следующий boot или refresh кэша — уже новая ревизия.\n11) Проверка на втором ПК (не том, на котором только что писали, если кэш ещё старый): логин шелла, запуск одной игры. Если второй ПК встал на старый кэш — refresh cache этого клиента в CCBoot, не «ещё раз Super Client на всех».\n12) Не оставляйте Super Client включённым «на потом»: writeback выключен, зал тормозит, game disk может быть locked.\n\nТолько киоск, без Super Client. Кнопка «Снять киоск» даёт explorer здесь и сейчас. После обычного reboot всё откатится. Так смотрят логи, не так обновляют золотой образ.\n\nНе путать\n• Pause / «пин после перезагрузки» на дашборде — PIN гостя, чтобы войти в ту же бронь. Образ не трогает.\n• Super Client с консоли iCafeCloud (ПК → Enable superclient) — тот же режим, если кнопки в CCBoot Client нет (ветка Cloud). Итог тот же: reboot → правки → Disable + save.\n• Booking /admin/licenses — пул Steam-аккаунтов, не смена VHD.\n\nКод: SetupScreen.qml блок Super Client; CcbootSuperClient; SecurityManager::unlockSystem; вход Main.qml openSetupScreen (Win/Ctrl+ПКМ). Документ сети: VLAN 20, сервер .10 — глава «Бездисковый сервер».",
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
                        'description' => "Основной способ: телефон + PIN брони + terminal_id → POST /api/shell/login → активация сессии, остаток времени, баланс и settings_pack (Cloud Saves).\n\nЧужой ПК: PIN верный, computer_id другой. Если целевой ПК занят на окно сессии (в т.ч. чужая бронь через 20 мин) → wrong_pc_occupied, «перейдите на своё место». Если свободен на всё время брони → wrong_pc_available, Да/Нет; accept_seat_change=true переносит computer_id/pc_ids и активирует здесь, исходное место освобождается (WrongSeatLoginService).\n\nДублирующий: QR на экране входа рядом с формой PIN (см. «Вход по QR»). PIN не убираем и не прячем.",
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Вход по QR',
                        'description' => "Дубль PIN: гость сканирует QR терминала в ЛК → сессия на этом ПК активируется без ввода PIN на клавиатуре шелла.\n\nShell (idle):\n• POST /api/shell/qr/challenge {terminal_id} → token, expires_at, qr_payload, throne (King дня на этом ПК, если есть);\n• картинка QR (qr_payload) на панели «ВХОД ПО QR»; над ней карточка King (ник, аватар, фраг/K/D);\n• poll GET /api/shell/qr/status?token= ~1.5 с; status=consumed → тот же loginSucceeded, что после PIN; expired → новый challenge.\n\nЛК: иконка QR-сканера (mobile) → redeem / quote / book (см. «Сканер QR»).\nНет брони на ПК: бронь «с сейчас» на выбранную длительность (≥60 мин, шаг 15), оплата с баланса или топап, затем activate+consume.\nБронь на другом ПК: тот же wrong_pc_occupied / wrong_pc_available, что у PIN.\n\nКод: ShellQrLoginService, ShellQrLoginController, NetworkManager::requestQrChallenge; TTL 120 с; тесты tests/Feature/ShellQrLoginTest.php.\nМиграция: shell_qr_challenges.",
                        'path' => null,
                        'audience' => 'Shell / Игрок',
                    ],
                    [
                        'title' => 'Баланс и poll',
                        'description' => 'Периодический опрос баланса. При опросе закрываются просроченные сессии; remaining считается согласованно с кабинетом (wall-clock / heal ends_at). В том же ответе — bounties, party_energy, ghost_coach, throne, lfg, lootbox.',
                        'path' => null,
                        'audience' => 'Shell / Система',
                    ],
                    [
                        'title' => 'LAN Bounty Board (охота за головами)',
                        'description' => "С шелла: POST /api/shell/bounties — ставка депозитом или напитком бара за голову игрока на соседнем ПК («Убей ПК-14 ножом» / «1v1 AWP»). Деньги эскроу с кошелька автора. Шелл шлёт GSI (kill/death/round_win) на POST /api/shell/gsi; облако склеивает фраг охотника и смерть цели в окне 4 с и сразу переводит депозит победителю или печатает кухонный слип на его место. Снять охоту: POST /api/shell/bounties/{id}/cancel. Доска: GET /api/shell/lan-live и poll /balance.",
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'King of the Hill (трон ПК)',
                        'description' => "Дневной рекорд на конкретном месте, не на весь клуб.\n\nGSI kill/death/round_win/match_win на POST /api/shell/gsi копится в кэше сессии (ключ throne:sess:{booking_id}, TTL 8 ч). С 3+ фрагов ник (GSI player.name, иначе users.name) и аватар пишутся в pc_thrones unique(computer_id, recorded_on). Ничья по киллам — лучше K/D; при 3+ фрагах лучший винрейт (раунды CS2 / матчи Dota) тоже коронует. Свой трон повторно не вызывает игрока.\n\nIdle: throne в POST /api/shell/qr/challenge и heartbeat → «KING OF THIS PC». Login / poll / lan-live отдают throne; чужой король — challenge. GSI может вернуть throne_crowned.\n\nАдминка: ♔ на плитках /admin/dashboard, owner POST /admin/api/computers/throne-reset.\nКод: PcThroneService. Тесты: LanLiveFeaturesTest.",
                        'path' => null,
                        'audience' => 'Shell / Игрок',
                    ],
                    [
                        'title' => 'Clan Wars (межзонный / межлокационный баттл)',
                        'description' => "Суммирование побед и очков CS2/Dota GSI между сторонами в реальном времени. Не турнир и не King of the Hill.\n\nРежимы. Зоны одной локации: Bootcamp (bootcamp / bootcamp-pro) vs Standard (сингл/дуо/трио/кватро). Локации сети: Club A vs Club B (clubs).\n\nАдмин: /admin/clan-wars (Киберспорт) — создать, «В эфир», завершить/отменить. Одна live-война (иначе отказ). Длительность 10–240 мин (по умолчанию 60), игра any / cs2 / dota. По ends_at live сама закрывается (expireOverdue). GSI match_win = 10 очков + победа, round_win = 1 очко. Дедуп по игроку/матчу/раунду. По окончании Elo фракции (старт 1000, swing 20+разница/5 до +30, проигравший −60% swing, ничья +5) и личный вклад в ЛК (+очки сессии, +15 за победу стороны).\n\nTV/PC lobby: GET /api/shell/overlays отдаёт clan_war и рисует счёт текстом на DAT mid_left / mid_right (ClanWarBanner в TV Shell). Idle TV поллит быстрее, пока война live. GET /api/shell/clan-wars/live без сессии. Сессионный шелл видит clan_war в lan-live / balance / gsi.\n\nЛК: блок Clan Wars — live-счёт, свой рейтинг, таблица кланов.\nКод: ClanWarService. Тесты: ClanWarsTest.",
                        'path' => '/admin/clan-wars',
                        'audience' => 'Supervisor+ / Shell / Игрок',
                    ],
                    [
                        'title' => 'Blind Matchmaking (пати в зале)',
                        'description' => "Кнопка «ПАТИ» в сайдбаре шелла. Соло указывает игру и ранг → POST /api/shell/lfg {game: cs2|dota|valorant, rank}. Облако ищет другой open-запрос в том же клубе, та же игра, rank_tier ±1. Уже сидящие в одном BookingGroup не матчятся между собой.\n\nНа экране: «Твой тиммейт на ПК-07 (ник, ранг)». Если сосед свободен и игроки ещё не рядом — автопересадка (BookingSeatTransferService, новый PIN); иначе can_sit и кнопка «Пересесть рядом». Discord клуба: CLUB_DISCORD в payload voice_url и кнопка в попапе. Матч склеивает соло в BookingGroup (pricing_snapshot.source=lfg) — котёл пати становится доступен, auto-fuel по-прежнему включает капитан.\n\nСнять поиск: POST /api/shell/lfg/cancel; то же на logout. TTL 20 мин. Статусы open / matched / seated / cancelled.\nКод: LanMatchmakingService. Тесты: LanLiveFeaturesTest.",
                        'path' => null,
                        'audience' => 'Shell / Игрок',
                    ],
                    [
                        'title' => 'Party Energy Pool (котёл пати)',
                        'description' => "Если бронь в BookingGroup на 2+ ПК (мультибронь друзей или LFG-матч в зале): общий котёл минут. Капитан POST /api/shell/party/energy/auto-fuel включает бесшовную подпитку. Любой из пати кладёт минуты POST /api/shell/party/energy/contribute (с депозита или со своей сессии). Когда у участника <90 с и GSI говорит in_match, сессия не выбивается в паузу: шелл держит игру, сервер сифонит 10 мин из котла (и на completeExpiredSessions). Без согласия капитана и пустой котёл — обычный logout.",
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Ghost Coach (ИИ-тактик)',
                        'description' => "GSI на шелле работает всю сессию. POST /api/shell/gsi + экономика/ульт. Шаблоны: «У вражеского Enigma на ПК-14 готов Black Hole» / «У них эко, жди раш с дробовиками» / AWP на линии. Если шаблон молчит — короткий LLM (DeepSeek, 4 с) по снимку GSI зала. Шёпот раз в ~28 с, галка в шелле (POST /api/shell/coach). F1 hold-to-talk дополнительно получает строку [GSI] в промпт (микрофон + live state). Пуш в наушники через SAPI/TTS.",
                        'path' => '/admin/ai-assistant',
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Lucky Seat Lootbox (дроп за стрик)',
                        'description' => "Интерактивный кейс прямо в шелле. Триггеры (один pending-дроп, кулдаун 3 ч на игрока): серия побед по GSI — 2 match_win подряд или 5 round_win CS2 подряд (сброс на match_loss / round_loss); либо 3 часа активной сессии от actual_started_at (poll /balance и GSI heartbeat). Истекает вместе с бронью (expires_at = ends_at).\n\nШелл поднимает оверлей поверх игры (showShellKeepGame). Открытие: POST /api/shell/lootbox/{id}/open — сервер крутит награду: бонус 50/75/100 ₽ (~55%) на bonus_balance (source=lucky_seat, не фискалится); напиток бара (~30%, заказ 0 ₽ «LUCKY SEAT: …» + кухонный слип, как охота); или одноразовый промокод RX-***** (~15%) на 10% периферии в REACTOR Store (store_promo_codes, 30 дней). Если напитка нет на складе — фолбэк на бонус. Неоткрытый кейс на logout открывается сам (settlePendingOnLogout).\n\nМагазин: поле промокода при создании /admin/store/orders, скидка с total, сверка телефона клиента с владельцем кода. Не клубный /admin/promocodes.\nКод: LuckySeatLootService. Тесты: LuckySeatLootTest.",
                        'path' => '/admin/store/orders',
                        'audience' => 'Shell / Игрок / Магазин',
                    ],
                    [
                        'title' => 'Игры на ПК',
                        'description' => 'Список игр, топы, запись запуска, take/free аккаунта, pause/unpause (новый PIN), обновление VDF-кэша. GET /api/shell/games передаёт terminal_id: если ПК в активном турнире с lock_games, каталог и featured сужаются до игры ивента.',
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
                        'title' => 'Instant Replay (клипы)',
                        'description' => "Cinematic Killcam / Reels: rolling-буфер последних 60 с на D:/ShellData/replay (том кэша), не на C: образа. ffmpeg + h264_nvenc если GPU умеет, иначе libx264. Бинарь: Replay/ffmpeg в config.ini или D:/Tools/ffmpeg.exe.\n\nТриггеры: F8 (Replay/hotkey), GSI-килл CS2 (Replay/auto_on_kill, Replay/kill_seconds=12, пауза Replay/kill_cooldown_sec=75), logout если Replay/save_on_logout. Kill-клип кропает центр туже (прицел). Scale 1080×1920, drawtext ник+клуб, лого, QR на /clips/{token}. Страница клипа — кнопка брони. Если вертикальный проход падает — грузится 16:9.\n\nPOST /api/shell/clips: .mp4 до 48 МБ, aspect=9:16|16:9, source=manual|kill|logout. Кабинет: плеер, ссылка, удаление, «В канал», «Привязать Telegram».\n\nTelegram: TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_USERNAME, webhook POST /api/telegram/webhook (TELEGRAM_WEBHOOK_SECRET). /start {token} из кабинета пишет users.telegram_chat_id — клип в личку. TELEGRAM_CLIPS_AUTO + TELEGRAM_CLIPS_CHAT_ID — канал клуба. sendVideo 1080×1920.\nКод: InstantReplay (Qt), GuestClipService, TelegramGuestService. Тесты: GuestClipTest.",
                        'path' => '/account/dashboard',
                        'audience' => 'Shell / Игрок',
                    ],
                    [
                        'title' => 'Cloud Saves (настройки игрока)',
                        'description' => 'Индивидуальный пак конфигов (sens CS2, cfg Valorant и т.д.) в user_settings. GET/POST /api/shell/settings; на logout можно передать settings_pack — при следующем входе на любой ПК пак приходит в login. Это текст ~2 МБ, не видео. Клипы Instant Replay лежат отдельно в guest_clips / кабинете.',
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Магазин с ПК',
                        'description' => 'Каталог, checkout и статус заказа прямо с терминала игрока. Если бронь в BookingGroup на несколько ПК, login/balance отдают party {count, names, computer_ids}. В корзине шелла галка «заказ на пати»: один чек капитана, в названии «Пати ПК-01, ПК-02: …», pc_name остаётся местом капитана (чтобы статус заказа на шелле не отвалился). Сплит по QR и N отдельных заказов нет — остаток бара один.',
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Logout',
                        'description' => 'Завершение активной брони на этом ПК, освобождение игровых аккаунтов; опционально сохраняет settings_pack в облако клуба. Перед complete снимается открытый LFG (LanMatchmakingService::cancel). Неоткрытый Lucky Seat открывается сам (LuckySeatLootService::settlePendingOnLogout). Instant Replay при save_on_logout сначала собирает вертикальный клип, потом logout.',
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Питание и WOL на PC Shell',
                        'description' => "POST /api/shell/power/heartbeat (~30 с) + MAC NIC + lan_ip + cache_ok/free_gb/data_root + maintenance + nic_link_mbps + nic_flap_events + SMART SSD + super_client + Steam/Epic inventory + patch_seed_port → online / очередь WOL / плитки кэша, линка, flap, износа, Super Client, LAN-seed.\nОтвет может содержать diskless, resync, patch_seed, patch_pull, nic_flap_acked, throne (King дня).\nPOST /api/shell/power/offline при штатном уходе.\nВ ответах logout/balance/poll может прийти power_action=reboot|shutdown по desired питания (бронь ± warmup); в техрежиме, Super Client и при активном patch_pull — none.\nMagic packet шлёт MikroTik из /api/power/wol-targets, не шелл и не облако напрямую. Настройка токена и warmup — .env CLUB_*; статусы на дашборде. Подробности — «Питание ПК» в Конфигурации; LAN-патчи и flap — главы ниже.",
                        'path' => '/admin/dashboard',
                        'audience' => 'Shell / MikroTik',
                    ],
                    [
                        'title' => 'LAN P2P-кэшер патчей (Steam/Epic)',
                        'description' => "Зачем. После обновления игр на одном ПК (обычно Super Client) остальные SSD-кэши не должны качать тот же билд с WAN — канал клуба забивается. Контур раздаёт уже лежащие на D: файлы по VLAN.\n\nЭто не SteamCDN, не Lancache и не BitTorrent: оркестрация через heartbeat booking, раздача — HTTP seed на шелле.\n\nПоток\n1) Seed: ПК с Super Client поднимает LocalHttpServer на всех IPv4, порт из config.ini [PatchCache] port=8745. Если Super Client выключен, booking выбирает временный сид: cache_ok, том D:, самый быстрый накопитель (nvme > ssd > unknown > hdd) и наибольший cache_free_gb. Гистерезис ~80, чтобы роль не прыгала.\n2) Heartbeat шлёт lan_ip + patch_seed_port + cache_media; сервер отвечает patch_seed {enabled, port, role=super|fallback|none}. Не выбранные слушатели гасятся.\n3) Ночь (club.patch_cache night_start/end, по умолчанию 1–6): fallback-сид держится desired=on (WOL), patch_ingest.enabled — шелл без админа стартует Steam -silent и по очереди steamcmd +app_update установленных игр на D:.\n4) Peer: LanPatchService сравнивает games_inventory (platform/appId/buildId). Если у сида build новее — в ответ heartbeat кладётся patch_pull {command_id, apps:[{p,id,b,peer_ip,peer_port}]}.\n5) Шелл (PatchCacheCoordinator) GET /manifest/{p}/{id} и /file/... с сида, копирует отличающиеся файлы на локальный install root (Steam steamapps/common или Epic InstallLocation).\n6) Ack: patch_pull_ack_id + result/message в следующем heartbeat; idle-shutdown не шлётся, пока pull в очереди, идёт ingest или ночной fallback-сид.\n\nГарды. Гостевая сессия на peer → busy_session, pull откладывается. На seed во время гостя сид гасится (кроме Super Client). Один живой сид в клубе: SC, иначе выбранный fallback.\n\nEnv booking (config/club.php patch_cache): CLUB_PATCH_NIGHT_START / CLUB_PATCH_NIGHT_END (1–6), CLUB_PATCH_INGEST, CLUB_PATCH_FALLBACK_HYSTERESIS (~80).\nЛимиты config.ini [PatchCache]: enabled, port, max_files=400, max_file_mb=512, night_start/end, ingest, steamcmd. Крупные депоты целиком не гоняются, это дифф свежих файлов манифеста.\n\nАдминка: плитка «mirror d:», в карточке — seed/mirror :port, cache_media, night-ingest. Тесты: tests/Feature/ShellLanPatchAndLinkFlapTest.php.\nКод: shell PatchCacheCoordinator + LocalHttpServer::listenAny; booking LanPatchService, ComputerPowerService::heartbeat.",
                        'path' => '/admin/dashboard',
                        'audience' => 'Техник / Shell',
                    ],
                    [
                        'title' => 'Hardware Health (свитч/микрик мыши и клавиатуры)',
                        'description' => "Зачем. HID-алерты ловят пропажу/подмену устройства, но не убитый микрик: мышь дабл-кликает сама, клавиша дребезжит или залипает. Технику нужен тикет на конкретный ПК, пока гость ещё за столом.\n\nШелл (HardwareHealthWatchdog) живёт поверх HID-сессии: startWatch логина ставит WH_MOUSE_LL + WH_KEYBOARD_LL, logout снимает. Синтетику (LLKHF_INJECTED / LLMHF_INJECTED) игнорирует — автологин Steam/Riot не триггерит.\n\nМышь: два DOWN одной кнопки (L/R/M) с интервалом ≤ bounce_gap_ms (~40 мс) — это не человеческий дабл-клик (~200–500 мс), а дребезг контакта. Нужно bounce_hits вспышек за window, растянутых на bounce_spread_ms, чтобы баттерфляй/джиттер за пару секунд не открыл тикет.\n\nКлавиатура: дребезг того же скан-кода (DOWN сразу после UP ≤ chatter_gap_ms) с тем же spread; либо залипание не-WASD/не-модификатора: клавиша down ≥ stuck_down_ms и за это время отпустили несколько других клавиш.\n\nТикет: POST /api/shell/incidents type=hardware_switch_fault, description «Проверить свитч/микрик на ПК-ХХ», severity high, payload {kind, reason=bounce|chatter|stuck_key, scan_code/button, hits}. Dedupe пока супервизор не закрыл. Cooldown config.ini [HardwareHealth] ~10 мин.\n\nЛента /admin/incidents, подпись типа «Неисправность свитча/микрика». System-tests «Здоровье станций»: warn при открытом тикете. Тесты: ShellStationWatchdogTest.",
                        'path' => '/admin/incidents',
                        'audience' => 'Техник / Shell / Админ',
                    ],
                    [
                        'title' => 'Деградация кабеля (Link Flap / патч-корд)',
                        'description' => "Зачем. Плохой патч-корд или порт даёт дропы линка с 1 Гбит до 100 Мбит — зал «тупит», но на дашборде раньше был только текущий nic_link_mbps. Нужен счётчик за смену и тикет технику.\n\nШелл (LinkFlapWatchdog): опрос NIC ~15 с (config.ini [LinkFlap] poll_ms / cooldown_ms). Событие: был ≥900 Мбит → стал ≤100 Мбит, либо рост InErrors при уже деградированном линке. Cooldown ~45 с, чтобы один дребезг не накрутил десятки.\n\nHeartbeat: nic_flap_events (сколько накопили) + nic_flap_payload {from_mbps,to_mbps,in_errors}. Сервер NicLinkFlapService::ingest копит computers.nic_flap_count в рамках открытой смены (AdminShift); без смены — окно 12 ч. Ответ nic_flap_acked — шелл сбрасывает pending.\n\nПорог: ≥2 события за смену → инцидент type=nic_link_flap, description «Заменить патч-корд на ПК-ХХ», severity high, лента /admin/incidents (dedupe пока не закрыт). Дашборд: жёлтая плитка «flap N», в карточке места — flap рядом с Мбит.\n\nSystem-tests «Здоровье станций»: warn при nic_flap_count≥2. Тесты: ShellLanPatchAndLinkFlapTest.\nКод: stationhealth::nicInfo (GetIfTable2), LinkFlapWatchdog, NicLinkFlapService, ShellIncidentService::TYPE_NIC_LINK_FLAP.",
                        'path' => '/admin/incidents',
                        'audience' => 'Техник / Shell / Админ',
                    ],
                    [
                        'title' => 'Климат на PC Shell',
                        'description' => 'Плитка климата: режимы auto / 50% / 75% / 100%. Сервер отдаёт desired; Shell пульсирует W5100 по LAN и шлёт fan/applied. Подробности железа — в «Вентиляция» (Конфигурация клуба).',
                        'path' => '/admin/fans',
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Свет на PC Shell',
                        'description' => "Плитка под климатом: кружки цвета + rainbow + ползунок яркости + галка «интерактив». Desired комнаты и каталог событий — GET /api/shell/light и heartbeat (light.events, light.events_from=admin|presets, при смене сцены light.play_event). Шелл apply → POST /api/shell/light/applied; ручной цвет/яркость — POST /api/shell/light; галка — POST /api/shell/light/interactive.\n\nПитание/сессия (вкл ПК, логин, логаут, выкл) всегда по вкладке /admin/lights?tab=interactive. GSI :59898 слушает всю сессию (охота / котёл / Ghost Coach / трон ПК / killcam). Световой overlay игр — только при галке, в том числе Game-Sense Ambient DMX Mirroring (зима Nuke/Ancient снаружи, огонь Inferno/molotov, blind 0.8 с по flashed). Гаснет по событию «компьютер выключен». Железо узла — вкладка «Узлы и комнаты». Личный светильник стола — привязка света к ПК/комнате места (один SpaceLight на space).",
                        'path' => '/admin/lights',
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Голосовой ИИ (F1 и приветствие)',
                        'description' => "Hold-to-talk во время сессии: запись с микрофона → POST /api/shell/ai-assistant → SpeechKit STT (или Whisper) → LLM (DeepSeek/OpenAI из админки) → SpeechKit/OpenAI TTS в наушники. В промпт добавляется живой [GSI] (карта, деньги, ульт, бомба), если игрок в матче.\n\nПосле логина: POST /api/shell/voice-greeting. Промпты и ключи — /admin/ai-assistant.\n\nОтдельно: Ghost Coach — пуш-шёпот по GSI без удержания F1 (шаблоны + LLM fallback).",
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
                        'description' => "Статус: реализовано (самообслуживание).\n\nAPI Shell: GET /api/shell/transfer/targets, POST /api/shell/transfer/preview|confirm (terminal_id + target_computer_id) — список свободных ПК. ЛК: GET /account/transfer/targets отдаёт targets + map_config/computers/occupied_ids/selectable_ids; модалка «Пересесть» показывает ClubMap. Shell UI: «ПЕРЕСЕСТЬ» (список, без SVG-карты).\n\nПравила:\n• только status=active, целевой ПК свободен до ends_at, тот же клуб, kind=pc;\n• дороже: доплата с баланса с сохранением времени; если денег мало — укоротить ends_at (prepaid value + баланс / новый ₽/ч);\n• дешевле: без возврата, время не растёт;\n• доплата считается от оплаченной ставки брони (price/duration), а не только от текущего hourly исходного ПК — иначе пакет 375 ₽ при hourly 400 даёт ложное «тариф тот же»;\n• бронь не complete: меняются computer_id/pc_ids; старый Shell получает session_active=false на balance-poll (soft-kick, без logout-complete);\n• вход на новом ПК — при пересадке выдаётся новый PIN; бронь уже status=active на целевом ПК (Shell UI сам не открывается). Login по PIN после пересадки — resume без повторного activate;\n• если PIN не ввели за 10 мин (transfer_pending_at) — откат на исходный ПК (reclaimAbandonedTransfers в reactor:update-statuses и /api/shell/balance), целевой снова available.\n\nЗаказы бара: pc_name = ПК активной сессии на момент заказа (см. «Магазин»).\n\nНе путать с «Сесть за ПК» без живой сессии (заготовка входа), с gift-причиной «Пересадка по вине клуба» и с «Пересесть рядом» из Blind Matchmaking (тот же transfer, но цель выбирает облако — соседний ПК к найденному тиммейту).",
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
                        'title' => 'Вход оператора клуба',
                        'description' => "GET/POST /admin/login, guard admin. Роли: admin, intern, supervisor, owner. Магазинные роли получают ошибку и ссылку на /store/login.\n\n«Устроиться» POST /admin/register создаёт intern + employment_pending, редирект /admin/salary (правила клуба, паспорт). Выход → /admin/login.\n\nГость на /admin/* → /admin/login; уже вошедший админ с формы логина уходит на homeRoute (дашборд / кабинет). Владелец входит здесь, не на /store/login.",
                        'path' => '/admin/login',
                        'audience' => 'Админ',
                    ],
                    [
                        'title' => 'Вход и устройство в магазин',
                        'description' => "Отдельный портал, не /admin/login. GET /store → /store/login. POST /store/login — только assembler / store_manager / senior_manager; клубный админ получает отказ. POST /store/register — сборщик или менеджер, локация store/both, employment_pending. GET/POST /store/hire, /store/hire/rules, /store/hire/fire-rules — анкета магазина (текст «визит в магазин»). После ПБ редирект /store/cabinet.\n\nПока employment_pending: RestrictOffDutyAdmin пускает только /store/hire* и /admin/logout; /store/cabinet, /admin/salary и склад редиректят на устройство. Выход → /store/login. Уволенный — тоже на /store/login.\n\nГость на /store/* → /store/login (bootstrap/app.php). CompClubStore UA разрешён на /store/* и разделах магазина; CompClubAdmin — редирект на /admin/login; CompClubClient — редирект на /.\n\nПодробности ролей, кабинета и смен — раздел «Магазин компьютеров».",
                        'path' => '/store/login',
                        'audience' => 'Сборщик / Менеджер',
                    ],
                    [
                        'title' => 'Документы при устройстве',
                        'description' => 'Тексты правил клуба и ПБ правит supervisor+ в /admin/config/documents (kind employment и fire_safety). Одни и те же разделы показываются и стажёру зала (/admin/salary), и кандидату магазина (/store/hire).',
                        'path' => '/admin/config/documents',
                        'audience' => 'Supervisor+ / Кандидат',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array{id:string,title:string,items:list<array{title:string,description:string,path:?string,audience:string}}>>
     */
    public static function filtered(string $sectionId = 'all', string $query = ''): array
    {
        $sections = self::sections();
        $sectionId = trim($sectionId);
        $query = trim($query);

        if ($sectionId !== '' && $sectionId !== 'all') {
            $sections = array_values(array_filter(
                $sections,
                static fn (array $section): bool => $section['id'] === $sectionId
            ));
        }

        if ($query === '') {
            return $sections;
        }

        $needle = mb_strtolower($query);

        return array_values(array_filter(array_map(static function (array $section) use ($needle): array {
            $section['items'] = array_values(array_filter(
                $section['items'],
                static function (array $item) use ($needle): bool {
                    $haystack = mb_strtolower(
                        $item['title']."\n".$item['description']."\n".$item['audience']."\n".($item['path'] ?? '')
                    );

                    return str_contains($haystack, $needle);
                }
            ));

            return $section;
        }, $sections), static fn (array $section): bool => $section['items'] !== []));
    }
}
