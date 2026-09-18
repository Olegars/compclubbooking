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
                        'description' => 'Карта и статусы ПК, сводка по выручке магазина, активным сессиям и новым гостям. Поиск игрока по телефону, пополнение депозита с кассы, выдача бонусного времени. Клик по плитке ПК: здоровье (линк Мбит, flap патч-корда за смену, износ SSD, Steam/Epic с диска, LAN-seed/mirror :port) и очередь Super Client (шелл забирает команду в heartbeat; пароль CCBoot только в config.ini на месте). Жёлтая плитка «flap N» — деградация кабеля; «mirror d:» — этот ПК раздаёт патчи по VLAN; «bsod» — нештатная перезагрузка, кнопка «Откатить образ» на проверенную ревизию (Rollback Markers). Тикет «Проверить свитч/микрик» — лента инцидентов, не плитка. Владелец: «Освободить компьютер» закрывает залипшую сессию, если шелл убили без logout.',
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
                        'description' => "Карточки точек вашей сети: /admin/store/locations. Тип type: club (только зал), store (только магазин), both. source=location. Саморегистрация магазина сажает кандидата на первую локацию store или both.\n\nВладелец переключает текущую локацию в шапке (POST /admin/store/location/switch) — от неё зависят склад, сметы, кабинет и команда старшего менеджера. Сотрудник магазина привязан к своему club_id, чужие локации не видит.\n\nЭто не регистрация чужого клуба в турнирах. Любой клуб (своя сеть, чужая сеть, независимый) заводится сам на GET/POST /clubs/join (source=open) и сразу попадает в список соперников Event Manager. Открытые клубы не показываются в переключателе локаций владельца.",
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
                        'description' => "Межклубный турнир на /admin/tournaments. Соперник — любой клуб, который сам зарегистрировался на /clubs/join (своя сеть, чужая сеть или независимый). Не вторая точка /admin/store/locations.\n\nСупервайзер клуба A жмёт «Вызвать клуб», заполняет регламент (игра, сетка, слот, состав с клуба, площадка, кто платит призы 1/2/3, заметки) и шлёт клубу B. B видит входящий вызов (бейдж в сайдбаре), может принять, отклонить или вернуть встречные условия. Пока статус pending — ивента нет. После accept создаётся общий Tournament (club_id хозяина + opponent_club_id), оба клуба видят его и набирают свой состав (лимит roster_size). Дальше как раньше: ПК арены, сетка Single Elim (bye до степени двойки), счёт вручную, «Завершить + призы» на депозит. Повторной выплаты нет (prizes_paid_at).\n\nЛокальный ивент без соперника — кнопка «Локальный ивент».\n\nПока ивент active и lock_games включён, GET /api/shell/games?terminal_id= на привязанных ПК отдаёт только игру турнира (в т.ч. полоса featured). Это не оверлей и не блокировка Win+Tab — каталог шелла.\n\nНе путать с Clan Wars (live-счёт зон/локаций по GSI, без согласования регламента). Не подключено: автогенерация Double Elim / Round Robin, Steam GC, split-check ЮKassa. Клипы — глава «Instant Replay».",
                        'path' => '/admin/tournaments',
                        'audience' => 'Supervisor+',
                    ],
                    [
                        'title' => 'Регистрация клуба в турнирах',
                        'description' => "Публичная страница GET/POST /clubs/join: любой желающий заводит клуб (название, город, опционально сеть/адрес/контакт) и учётку управляющего. source=open, type=club, tournament_open=true, employment_pending не ставится — это не найм в чужую сеть. После входа редирект на /admin/tournaments, клуб сразу в списке соперников.\n\nНе путать с POST /admin/register («Устроиться» → intern + employment_pending на первую operational-локацию) и с /admin/store/locations (точки одной сети, source=location, переключатель владельца).",
                        'path' => '/clubs/join',
                        'audience' => 'Публично',
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
                        'title' => 'Coach Whisper: Eco-Round & Drop Synchronizer',
                        'description' => "Расширение Ghost Coach для пати (BookingGroup, 2+ ПК). В freeze CS2 облако суммирует банк команды по GSI всех мест группы. Сумма < N×2000$ → одна фраза всей пати: «Эко-раунд, копим на бай». Иначе при избытке у одного (≥5500$) и союзнике без AWP (<4750$) — «Скинь AWP на ПК-05». Одна строка на раунд, шеллы забирают её своим POST /api/shell/gsi. Галка Ghost Coach.\nПодробности — глава Shell «Coach Whisper».",
                        'path' => '/admin/ai-assistant',
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
                    [
                        'title' => 'REACTOR AC (план античита)',
                        'description' => "Клиент на домашний ПК (служба user-mode, не свой kernel в MVP). На дедик CS2 — только с одноразовым connect_token как password; плагин CounterStrikeSharp сверяет SteamID. Бан скоупа match_making/tournament не закрывает зал. Полный план — «Античит домашней игры (REACTOR AC)».",
                        'path' => '/admin/docs',
                        'audience' => 'Supervisor+ / Игрок / Система',
                    ],
                ],
            ],
            [
                'id' => 'anticheat',
                'title' => 'Античит домашней игры (REACTOR AC)',
                'items' => [
                    [
                        'title' => 'Назначение: домашний ПК на сервер клуба',
                        'description' => "Зачем. Игрок со своего домашнего Windows ставит клиент REACTOR AC и только с живым клиентом попадает на дедик клуба (CS2, адрес хоста из ARENA_CS2_CONNECT). Общего пароля лобби нет: каждый получает одноразовый connect_token на 60–120 с и коннектится `connect ip:port; password <token>`. Плагин CounterStrikeSharp сверяет SteamID + пароль с облаком. Так клуб открывает рейтинг и турниры тем, кто сидит дома.\n\nКоммерческий потолок реализации — конец фазы 3 (Protocol Gate + Integrity Check + HWID/evidence + демо). Свой kernel (фаза 4) — R&D / Conditional, не спринт MVP. Не обещать «как FACEIT по силе» без партнёрского SDK и юрлица под WHQL.\n\nЧто не цель. PC Shell, киоск, VAC/EAC/Vanguard, сервера FACEIT. Игрок зала (VLAN 20, живая сессия шелла) может идти на тот же дедик как station_trusted — без домашнего установщика. Бан онлайн-скоупа не закрывает бронь ПК и бар, пока управляющий не поставил full_ban.\n\nСтатус. ТЗ / план. Нет установщика, нет /api/ac/*, нет плагина CSS, нет фичи reactor_ac.",
                        'path' => '/account/arena',
                        'audience' => 'Owner / Игрок / Система',
                    ],
                    [
                        'title' => 'Как у FACEIT по продукту, не по драйверу',
                        'description' => "У FACEIT берём продуктовую дугу, не кольцо-0 в v1.\n• установщик на домашний Windows;\n• служба user-mode, жива до connect;\n• облако: сессия, heartbeat, вердикт, бан аккаунта + HWID;\n• дедик не пускает без персонального connect_token;\n• evidence для апелляции.\nKernel ACE на домашнем ПК у FACEIT закрывает инжекты ядра. Свой аналог у клуба — не обязательство MVP: WHQL в 2026 почти закрыт для ИП, BSOD на чужом железе бьёт по бренду. Фаза 4 = R&D или партнёрский сертифицированный SDK, и только при крупном призовом фонде.\n\nПоток MVP. 1) Скачал AC. 2) Установил службу (Authenticode, без sys). 3) Вошёл аккаунтом ЛК. 4) Heartbeat alive + integrity ok → облако выдаёт connect_token. 5) Клиент/ЛК даёт `connect host:port; password TOKEN`. 6) CSS на авторизации: steam_id + password → POST /api/ac/server/validate. 7) Нет пары или heartbeat мёртв — kick. 8) Нарушение — kick + evidence + бан скоупа match_making / tournament, не full_ban без решения supervisor.\n\nЗал. Бан HWID не вешать на PC-08. station_trusted = computer_id живой сессии.",
                        'path' => '/account/arena',
                        'audience' => 'Owner / Игрок',
                    ],
                    [
                        'title' => 'Модель угроз домашнего ПК',
                        'description' => "Машина игрока. Он локальный администратор. В user-mode обход тривиален (suspend потоков службы, loopback-прокси к API, VM/песочница). Фазы 1–3 с этим не соревнуются и не обещают ловить приватный kernel-чит.\n\nПозиция фаз 1–3 — Protocol Gate & Integrity Check:\n• нет живого клиента → нет connect_token → на дедик не войти;\n• testsigning on (bcdedit) → отказ в токене;\n• гипервизор/VM (Hyper-V sandbox, VMware, VirtualBox) → отказ в токене;\n• базовый user-mode скан: отладчик, оверлей, известные публичные инжекторы — finding, не «античит уровня FACEIT».\n\nКласс A (паблик). Макросы, оверлеи, публичные лоадеры — частично фаза 3 + демо/GOTV.\nКласс B (вход без клиента). Прямой IP и общий пароль лобби — закрываем полным отказом от статического password; пароль = одноразовый токен, привязанный к steam_id.\nКласс C (ядро, DMA). Не закрывается до фазы 4/партнёра. Для денежных призов: залог и/или паспорт в ЛК + ручной разбор демо, не свой Ring0.\nКласс D. Буст, чужой аккаунт. 2FA позже. Не лица FACE-01.\n\nКлиент шлёт факты. Вердикт только в облаке. Сигнатуры приватного софта в git не класть.",
                        'path' => null,
                        'audience' => 'Система / Техник',
                    ],
                    [
                        'title' => 'Архитектура: клиент, облако, дедик',
                        'description' => "Три контура. Booking в розетки дома не ходит — клиент и плагин сами ходят HTTPS к APP_URL.\n\n1) Клиент REACTOR AC (репо не Qt-шелл). MSI/EXE, служба Windows + трей. Без своего .sys на MVP. Задачи: логин ЛК, HWID, heartbeat, integrity (testsigning / VM / публичный скан), запрос connect_token, evidence по команде облака.\n\n2) Облако. Связка на 60–120 с: match_id + steam_id + connect_token + client_heartbeat_alive. Токен — короткий alphanumeric, одноразовый. Не JWT в setinfo и не client convar: в CS2 кастомные клиентские convar жёстко режет Source 2, длинный токен дропается, правка клиента игры = риск VAC. Пароль сервера = этот токен.\n\n3) Дедик CS2. CounterStrikeSharp (.NET 8 / Metamod). sv_password пустой; Gate читает пароль connect, валидирует в облаке, keep-alive 30 с. Глава «Плагин CSS».\n\nКлиент не банит сам. Зал: не IP-префикс, а POST /api/ac/server/validate-station (живая сессия шелла + тот же SteamID). Глава «Слепые зоны gate». Hot-reload / 50x keep-alive / пин Intermediate — глава «Микро-риски кодинга».",
                        'path' => '/admin/config/features',
                        'audience' => 'Система',
                    ],
                    [
                        'title' => 'Клиент: установщик и служба',
                        'description' => "Дистрибутив. GET /ac/download после логина ЛК, манифест GET /ac.json {version, url, sha256, min_os}. Подпись Authenticode (не EV-драйвер). Автообновление до матча, не в раунде.\n\nУстановка MVP. Служба, не kernel. Win10/11 x64. Имя службы ReactorAcSvc, старт Automatic. Трей: логин, «готов к серверу», целостность, кнопка «Переподключиться к матчу» (новый token на тот же match_id, строка connect в буфер). SSL Pinning на /api/ac/* обязательно: SPKI Intermediate CA (не leaf Let's Encrypt) + fallback pin-hash в сборке; не доверять User Root CA Windows (Fiddler/mitmproxy иначе снимет connect_token). Токен не выдаётся, если testsigning, VM/гипервизор, служба не pulsing или пиннинг сломан.\n\nHWID: digest SMBIOS UUID + диск системного тома + постоянный NIC + MachineGuid, salt версии. Не сырые серийники в публичном JSON.\n\nEvidence (фаза 3): скрин, список процессов (имя + sha256), без дампа RAM, ≤3 МБ. Не в /clips и не в Telegram.\n\nРепозиторий клиента C:\\Qt\\reactorac (целевой). Плагин дедика — отдельный проект CounterStrikeSharp. Свой .sys не класть в эти репо до решения по фазе 4.",
                        'path' => '/ac/download',
                        'audience' => 'Игрок / Техник',
                    ],
                    [
                        'title' => 'Match Connect Token (не setinfo, не общий пароль)',
                        'description' => "Сейчас. ArenaDuelService::connectUri клеит ARENA_CS2_CONNECT + общий password. Это дыра: кто увидел URI, заходит без AC.\n\nЗапрещено. Длинный JWT/ticket через client setinfo / кастомный convar: Source 2 режет, токен дропается, правка клиента CS2 = риск VAC. Общий sv_password на дедике: движок отвергнет индивидуальный token до плагина. Whitelist SteamID с облака на дедик — костыль, не канон.\n\nКанон. Короткий alphanumeric connect_token = штатный пароль команды connect (не кастомный convar игры).\n1) Клиент (heartbeat alive, integrity ok) POST /api/ac/connect-token {match_id}.\n2) Облако: match_id + user_id + steam_id + token, два TTL (см. плагин CSS): token_connect_ttl 60–120 с на handshake, session_ttl пока жив heartbeat службы.\n3) `connect ip:port; password TOKEN`. Хост из ARENA_CS2_CONNECT.\n4) На дедике sv_password=\"\" — проверку пароля делает CSS. Validate {steam_id, token}. Успех → token consumed. Повтор той же строки — kick. Краш CS2 — новая кнопка «Переподключиться» в трее AC.\n\nЗал. Не StartsWith(192.168.20.): дедик dual-homed и VPN ломают префикс. Канон — validate-station в облаке (сессия шелла + SteamID). Глава «Слепые зоны gate».\nDota — после CS2. MVP: CS2 + CounterStrikeSharp.",
                        'path' => '/admin/tournaments',
                        'audience' => 'Техник / Система',
                    ],
                    [
                        'title' => 'Плагин CSS: хуки, sv_password, keep-alive',
                        'description' => "Стек. C# / .NET 8, CounterStrikeSharp на Metamod:Source. Модуль ReactorAC Gate. Секрет и URL API только в конфиге дедика, не в клиенте. Booking сам на UDP сервера не ходит.\n\nЛовушка sv_password. Если нативному sv_password задать общий пароль, Source 2 отсеет игрока с индивидуальным token до хука плагина. В режиме gate sv_password держать пустым (\"\"), либо перехватить OnValidateServerPassword. Проверку пароля делегирует плагин: пароль подключения (инфо-строка / INetChannel, не кастомный setinfo-JWT). Пустой или не похож на token — сразу kick, без HTTP.\n\nЖизненный цикл. Клиент: connect ip:port; password TOKEN → движок (sv_password пуст) → CSS до спавна и занятия слота. Целевой хук: OnClientAuthorized (slot, steamId) или более ранний PreConnect, если API версии CSS его даёт стабильно. EventPlayerConnectFull — поздно: слот уже занят. Ботов не валидировать.\nПоток хука:\n1) steamId есть в _activeSessions для текущего match_id → пропуск без token (changelevel / реконнект в окне 60 с). Иначе дальше.\n2) IP в 192.168.20.0/24 (с интерфейса дедика, не StartsWith как единственный bypass) → кандидат зала: POST /api/ac/server/validate-station {client_ip, steam_id}. 200 только при active-сессии шелла на этом lan_ip и том же SteamID. Иначе kick (ПК idle / нет сессии / чужой Steam). Нет привязки Steam — kick «привяжите Steam». IP не из подсети зала → домашний путь, не station.\n3) Дом: пароль connect = token; пустой / не формат — kick без HTTP.\n4) Сразу _pendingValidation.Add(steamId). AddCommandListener(\"jointeam\") и joinclass: если steamId в _pendingValidation → HookResult.Handled (не спавнить). HTTP validate в Task.Run.\n5) Успех: убрать из _pendingValidation, _activeSessions[steamId]=session, token consumed. Ошибка: Server.NextFrame → player.Disconnect, убрать pending.\n\nKeep-alive. Таймер CSS AC_CSS_KEEPALIVE_INTERVAL (30 с): heartbeat-check. Дом: тишина службы > AC_HEARTBEAT_STALE_SEC (25) → kick. Зал: нет сессии шелла → kick. Транспорт 50x/timeout API — не кик: 1 штрафной цикл, повтор через AC_KEEPALIVE_HTTP_RETRY_SEC (10 с); кик только после двух подряд сбоев связи. 200+deny_* кикает сразу (вердикт, не сеть). _activeSessions не чистить на changelevel; снятие — OnClientDisconnect + задержка 60 с (реконнект) или конец матча.\n\nHot-reload CSS. Load/css_plugin_reload обнуляет RAM-словари. На Load: POST /api/ac/server/active-sessions {match_id} → залить _activeSessions из облака. OnClientAuthorized, слот уже в игре, ключа нет: silent-validate (живая session облака, без нового token). Не требовать connect_token у всего лобби.\n\nДва TTL в облаке. token_connect_ttl (60–120 с) — выдать connect и пройти handshake. session_ttl — пока жив heartbeat службы. Handshake на 115-й секунде connect_ttl: validate ok, статус consumed сразу; дальше держит session_ttl, не connect_ttl. Иначе медленный HDD/загрузка карты сжигает токен до validate.\n\nПерезаход. Токен одноразовый: старая строка из консоли после краша CS2 не работает. Трей клиента: «Переподключиться к матчу» → новый connect_token на тот же match_id (матч жив, не бан, heartbeat alive) и копирует connect в буфер.\n\nКод (целевой): ReactorAcGatePlugin; ConcurrentDictionary _activeSessions; HashSet _pendingValidation; http_fail_streak; AddCommandListener jointeam; ValidateTokenAsync; ValidateStationAsync; RestoreSessionsAsync. Тесты: AcConnectTokenTest, AcValidateStationTest, AcHeartbeatCheckTest, AcActiveSessionsReloadTest. Стенд: чужой token, VLAN без сессии, jointeam за 200 мс, changelevel, css_plugin_reload, один 50x keep-alive, kill службы, реконнект <60 с.",
                        'path' => '/admin/tournaments',
                        'audience' => 'Техник / Система',
                    ],
                    [
                        'title' => 'Слепые зоны gate: зал, jointeam, MITM, changelevel',
                        'description' => "Закрыть в коде фазы 2, не оставлять на «потом».\n\n1) Зал — два фактора, не StartsWith как bypass. Фактор A: Src IP с точки зрения дедика ∈ 192.168.20.0/24 (маска из AC_CLUB_SUBNET). Нет в подсети → домашний контур (token), station не вызывать. Dual-homed дедик + NAT/VPN: префикс один не доверять. Фактор B: POST /api/ac/server/validate-station {client_ip, steam_id}. Облако: heartbeat шелла с этим lan_ip, status=active, привязанный steam_id совпал. Нет сессии (ПК выкл, экран логина) или чужой Steam — kick. Оба фактора обязательны.\n\n2) Спавн в окне Task.Run (100–300 мс, при лаге больше). В OnClientAuthorized сразу _pendingValidation.Add(steamId). AddCommandListener(\"jointeam\"): steamId в pending → HookResult.Handled. То же joinclass. Успех validate — Remove. Ошибка — player.Disconnect в Server.NextFrame. Не ждать ConnectFull.\n\n3) Changelevel / переход карты. Движок переподключает, token consumed → повторный validate даст token_already_used на весь лобби.\nПамять плагина: ConcurrentDictionary<ulong, ActivePlayerSession> _activeSessions (match_id, kind=home|station, validated_at). OnClientAuthorized: ключ есть и match_id текущий → пропуск без token. Снятие записи не на changelevel, а OnClientDisconnect с задержкой 60 с (реконнект после краша/перехода карты) либо конец матча. Hot-reload CSS обнуляет словарь — глава «Микро-риски кодинга».\n\n4) SSL Pinning в ReactorAcSvc. Public Key Pinning на APP_URL, не доверять User Root CA Windows (Charles/Fiddler/mitmproxy). Пиннить SPKI промежуточного CA (Intermediate), не leaf: Let's Encrypt крутит лист раз в 90 дней и сломает всех клиентов. В сборке — primary + fallback pin-hash (ротация intermediate). Пиннинг сломан — нет connect_token. Плюс биндинг token↔steam_id на дедике.",
                        'path' => '/admin/tournaments',
                        'audience' => 'Техник / Система',
                    ],
                    [
                        'title' => 'Микро-риски кодинга: hot-reload, HTTP API, ротация TLS',
                        'description' => "Закрыть на этапе реализации фазы 2, иначе ложный массовый кик на живом лобби.\n\n1) Hot-reload плагина CSS. css_plugin_reload / повторный Load чистит ConcurrentDictionary _activeSessions в RAM (в черновиках — ConnectedPlayers). Игроки уже на карте, token consumed. Повторный validate без фолбэка = token_already_used на всех.\nКупирование. На Load / OnMapStart: POST /api/ac/server/active-sessions {match_id} (server_secret) → список живых session облака, залить _activeSessions. OnClientAuthorized для уже играющего слота, ключа нет в RAM: silent-validate POST /api/ac/server/validate-session {steam_id, match_id} — облако смотрит живую session, token не жжёт. Нет живой session — тогда обычный путь (дом: token; зал: validate-station). Не выдавать новый connect_token всему лобби из-за reload админа.\n\n2) Падение HTTP API бэкенда. Лаг дедик↔APP_URL на keep-alive кикнет всех, хотя службы живы.\nКупирование. heartbeat-check: 50x или timeout = транспортный сбой, не stale heartbeat. Один штрафной цикл (grace cycle): не кикать, http_fail_streak++. Повтор через AC_KEEPALIVE_HTTP_RETRY_SEC=10. Кик только при двух подряд транспортных сбоях (AC_KEEPALIVE_HTTP_GRACE_CYCLES=1). 200 с deny_stale / deny_no_session — кик сразу: это вердикт облака. Счётчик сбрасывать на 200 allow. Не путать сеть и «служба убита».\n\n3) Ротация SSL сертификата API. Let's Encrypt ~90 дней меняет leaf. Жёстко зашитый SPKI листа в ReactorAcSvc оборвёт connect_token у всех домашних ПК до пересборки клиента.\nКупирование. Пинить публичный ключ Intermediate CA, не leaf. В бинарнике два pin-hash: primary и fallback (на смену R10/R11 и аналоги). User Root CA по-прежнему отвергать. Смена промежуточного CA — выкатить клиент с новым fallback до отзыва старого пина.",
                        'path' => '/admin/tournaments',
                        'audience' => 'Техник / Система',
                    ],
                    [
                        'title' => 'Фазы 0–2: оферта, Protocol Gate',
                        'description' => "Фаза 0 (1–2 нед.). Оферта: клиент на домашний ПК, HWID, скрины, скоупы бана. Kick-тексты. Фича reactor_ac=off: пока off — поведение как сейчас (это долг: общий пароль ещё жив). Стек дедика зафиксировать: CounterStrikeSharp. Приёмка: заглушка /ac/download, документ в /admin/config/documents.\n\nФаза 1 (часть окна 3–4 мес. MVP). Служба + heartbeat + integrity, без kick на дедике. POST /api/ac/heartbeat. Отказ заранее логировать: testsigning, VM. ЛК: «AC онлайн / не установлен». Fair-play — лента сессий. Тесты AcClientSessionTest.\n\nФаза 2. Protocol Gate. sv_password=\"\", _pendingValidation+jointeam Handled, двухфакторный зал, _activeSessions+grace 60 с, pinning Intermediate+fallback, heartbeat-check 30 с + 1 HTTP grace cycle, active-sessions после hot-reload.\nПриёмка 2: дома без AC — kick; чужой Steam на token — kick; consumed — token_already_used; VLAN без сессии шелла — kick; VLAN+сессия+свой Steam — без token; jointeam в pending — не спавн; changelevel — лобби живо; disconnect <60 с — без нового token; kill службы 40 с — kick; mitm Root CA — нет token; css_plugin_reload — лобби живо без нового token; один 50x keep-alive — никто не кикнут; два подряд timeout — kick; смена leaf LE — клиент жив (пин Intermediate).",
                        'path' => '/admin/config/features',
                        'audience' => 'Система / Игрок',
                    ],
                    [
                        'title' => 'Фаза 3 коммерческая; фаза 4 — R&D',
                        'description' => "Фаза 3 (1–2 мес. после gate). Evidence и HWID. Паблик-софт, макросы, перезаход читера. Скрин/proclist, GOTV/demo разбор. Бан в БД с scope: match_making | tournament | full_ban. Пойман на арене дома → теряет онлайн-матчмейкинг и/или турниры; бронь ПК и бар не трогаем, пока supervisor не поставил full_ban. Денежные призы: обязательный залог и/или паспорт в ЛК + ручной разбор демо, не «драйвер поймает». Апелляция supervisor+. Тесты AcBanTest, validate 403 deny_banned.\n\nФаза 4 Kernel — статус R&D / Conditional, не дорожная карта v1.\nПочему не писать свой Ring0: в 2026 WHQL без юрлица (ООО/АО), EV-сертификата и Dun & Bradstreet + пакет в Microsoft Hardware Dev Center практически недоступен ИП; BSOD на тысячах домашних конфигов (антивирусы, чипсеты, разгон) = негатив на клуб; конфликт с Vanguard/EAC/FACEIT AC. Коммерческий путь малого оператора: остановиться на фазе 3 либо купить сертифицированный партнёрский SDK, не писать sys с нуля. Старт фазы 4 — только при подтверждённом пуле турниров с крупным призовым фондом и отдельном бюджете (6–12+ мес., экстремальная сложность).\nНе делать: unsigned sys игрокам, реверс чужих AC, боевые читы на стенде.\n\nDMA / ядро читов. Потолок без фазы 4. Не обещать закрытие в MVP.",
                        'path' => '/admin/incidents',
                        'audience' => 'Owner / Система',
                    ],
                    [
                        'title' => 'API, таблицы, фича, дистрибутив',
                        'description' => "Фича reactor_ac mode=off|telemetry|gate. Пустой club_features AC не включает. Конфиг (env облака + club_features + css.json на дедике), дефолты:\n• AC_TOKEN_CONNECT_TTL=120 — жизнь token от выдачи до первого validate;\n• AC_HEARTBEAT_INTERVAL=10 — пульс ReactorAcSvc в облако;\n• AC_HEARTBEAT_STALE_SEC=25 — тишина службы → keep-alive кик;\n• AC_CSS_KEEPALIVE_INTERVAL=30 — батч heartbeat-check с дедика;\n• AC_BAN_DAYS_TEMP=7 — первый бан scope=match_making;\n• AC_EVIDENCE_RETENTION_DAYS=90 — скрин/proclist для апелляции;\n• AC_CLUB_SUBNET=192.168.20.0/24 — фактор A зала;\n• AC_DISCONNECT_GRACE_SEC=60 — снятие _activeSessions после OnClientDisconnect;\n• AC_KEEPALIVE_HTTP_GRACE_CYCLES=1 — штрафных циклов keep-alive при 50x/timeout до кика;\n• AC_KEEPALIVE_HTTP_RETRY_SEC=10 — повтор heartbeat-check после транспортного сбоя;\n• AC_SERVER_SECRET — только дедик и .env.\nПинны TLS — в сборке ReactorAcSvc (SPKI Intermediate + fallback), не в .env.\n\nAPI клиента (Bearer, SSL pinning Intermediate+fallback):\n• POST /api/ac/login, heartbeat, connect-token {match_id}, events, evidence; GET /api/ac/policy.\nAPI дедика (server_secret):\n• POST /api/ac/server/validate {steam_id, token, ip} → allow consumed | deny_* (в т.ч. token_already_used);\n• POST /api/ac/server/validate-station {client_ip, steam_id} — фактор B зала (сессия шелла + Steam), не замена маски;\n• POST /api/ac/server/heartbeat-check {steam_ids[]};\n• POST /api/ac/server/active-sessions {match_id} — refill _activeSessions после hot-reload CSS;\n• POST /api/ac/server/validate-session {steam_id, match_id} — silent-validate живой session, token не жжёт;\n• kick-ack. Fail-closed на вердикт; fail-open на один транспортный сбой keep-alive (grace cycle).\n\nТаблицы: ac_sessions, ac_hwids, ac_tickets (connect_expires_at, consumed_at), ac_events, ac_evidence, ac_bans (match_making|tournament|full_ban).\nКод: reactorac; ReactorAcGatePlugin (_pendingValidation, _activeSessions, http_fail_streak); AcTicketService / AcServerGuard. Тесты: AcConnectTokenTest, AcValidateStationTest, AcHeartbeatCheckTest, AcActiveSessionsReloadTest, AcBanTest.",
                        'path' => '/admin/config/features',
                        'audience' => 'Система',
                    ],
                    [
                        'title' => 'ЛК, админка, сервер, зал',
                        'description' => "ЛК /account/arena. Баннер скачать AC, статус готов/не запущен/бан (с скоупом). Connect показывает персональную строку с одноразовым password; общий пароль лобби не светить. После краша CS2 — «Переподключиться» в трее AC, не старая строка консоли. Пока AC offline — кнопки нет. Бар и бронь ПК от AC не зависят, кроме full_ban.\n\nСайт: «Играй с дома», Win10/11, что это Protocol Gate на сервер клуба, не античит уровня FACEIT.\n\n/admin/fair-play: сессии, HWID, кики CSS, evidence, смена scope, pardon. Дашборд зала — счётчик домашних на сервере, не flap/drift.\nШелл /api/ac/* не вызывает. TV античит не показывает.\nДенежные призы: в ЛК залог/паспорт до выдачи connect_token на турнир (фаза 3), не фаза 4.",
                        'path' => '/account/arena',
                        'audience' => 'Игрок / Supervisor+',
                    ],
                    [
                        'title' => 'Право, подпись, конфликт с чужими AC',
                        'description' => "152-ФЗ. HWID, процессы, скрин — по оферте соревнования, добровольная установка. Без клиента на дедик не пускаем. Срок хранения, роль supervisor+/owner, не публикация. Кейлог не писать.\n\nПодпись клиента MVP — Authenticode (0 ₽ сверх обычного кода). EV + WHQL на свой драйвер: юрлицо, проверка Dun & Bradstreet, Hardware Dev Center — для ИП путь фактически закрыт; в ТЗ не планировать как этап релиза.\n\nЧужие AC (FACEIT, Vanguard, EAC) не патчить. MVP без своего kernel: регресс Valorant/FACEIT на домашнем ПК должен проходить.\nБоевые читы на проде не держать. Стенд — VM (для теста детекта VM) и отдельные чистые машины для gate.\nСпорный кик — рефери, как force-refund арены.",
                        'path' => '/admin/config/documents',
                        'audience' => 'Owner / Юрист',
                    ],
                    [
                        'title' => 'Дорожная карта, оценка, приёмка',
                        'description' => "Реализация строго до конца фазы 3. Фаза 4 не открывать без крупного призового фонда, юрлица и партнёрского SDK.\n\nСводка фаз\n• 1–2 MVP Gate (3–4 мес., сложность средняя): закрывает вход без клиента и прямой IP/общий пароль. Риск ложных киков минимальный. Сертификация: Authenticode, 0 ₽ сверх кода.\n• 3 Evidence & HWID (ещё 1–2 мес.): паблик-софт, макросы, перезаход. Риск низкий при ручном апелляте. Сертификация та же.\n• 4 Kernel (6–12+ мес., экстремально): инжекты ядра, чтение памяти. Риск высокий (BSOD, Vanguard/EAC). EV+WHQL, юрлицо. Статус R&D / Conditional.\n\nПорядок работ. 0 оферта → 1 служба+integrity → 2 CSS (sv_password пустой, OnClientAuthorized, keep-alive) + password=token → 3 баны со scope + демо/залог. Не Qt-шелл, не setinfo, не свой sys.\n\nЧеклист MVP (gate).\n• Фича off — как сейчас (долг общего пароля).\n• gate on — sv_password пустой; дома без AC / чужой SteamID / повторный token — kick; keep-alive кикает глушеную службу; «Переподключиться» после краша CS2.\n• PC-08: VLAN + живая сессия шелла + свой Steam — играет; VLAN без сессии — kick.\n• SSL pinning: подмена Root CA — нет token; ротация leaf LE — клиент жив (пин Intermediate + fallback).\n• changelevel и css_plugin_reload — лобби на месте без нового token (active-sessions / silent-validate).\n• keep-alive: один 50x/timeout — без кика; два подряд — kick; 200 deny_stale — kick сразу.\n• testsigning и VM — токен не выдаётся.\n• /ac/download + sha256.\n• scope match_making не закрывает бар и бронь.\n\nНе успех. Convar/setinfo как канал тикета. Общий пароль лобби в проде с gate. station_trusted только по IP. Агент в шелле. Бан HWID PC-08. Unsigned драйвер. Обещание «ловим приват» в user-mode. Пин leaf Let's Encrypt (без Intermediate + fallback). Keep-alive кик на первый 50x/timeout. Hot-reload CSS без refill active-sessions.\n\nСвязанные главы. Арена (connect URI), турниры, ЛК, оферта.",
                        'path' => '/admin/system-tests',
                        'audience' => 'Owner / Система',
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
                        'title' => 'Фичи',
                        'description' => "Страница /admin/config/features (Конфигурация → Фичи), supervisor+. Тумблеры опциональных контуров клуба и их настройки. Выключенная фича не рисуется в шелле и не пишет события (GSI/дропы/оверлеи).\n\nШелл: вход по QR (TTL кода), King of the Hill (мин. фраги на ПК, не путать с царём горы арены), пати в зале (TTL и допуск ранга), охота (мин/макс ставка), котёл пати (порция и порог), Арена дуэлей (дуэль/битва без ставок, Elo, царь горы за вечер, TTL вызова и ЛК, допуск ранга, дисконнект, кулдаун, first-to CS2, ваучер), Lucky Seat (кулдаун, стрики, бонусы, промокод), Ghost Coach (пауза шёпота), Coach Whisper (порог эко и дроп AWP), Rage-Smash (напиток, метка NVR), Cloud Saves, магазин с ПК, пересадка, Shell-оверлеи (не live-счёт Clan Wars), Instant Replay (автоклип, logout, длина killcam).\n\nСтанции и образ: Rollback Markers (тикет при BSOD, автоподтверждение ревизии, сколько ревизий хранить), Hardware Health, LAN P2P-патчи, деградация кабеля (порог flap за смену).\n\nКиберспорт: Clan Wars (длительность по умолчанию; пункт сайдбара прячется), турниры (lock каталога и сетка). План домашнего античита REACTOR AC (connect_token + CounterStrikeSharp, фазы 1–3; kernel только R&D) — в доках, тумблера ещё нет.\nМаркетинг: достижения, промокоды, заявки на игры, бонусы за отзывы.\n\nНе на этой странице: голосовой ИИ F1 (тумблер /admin/ai-assistant), гостевой Wi-Fi (WIFI_ACCESS_ENABLED), свет/климат (свои страницы).\n\nПустой club_features = всё включено, как до страницы. Шелл забирает features из heartbeat / login / lan-live. Код: ClubFeatureCatalog, ClubFeatureService. Тесты: ClubFeaturesTest.",
                        'path' => '/admin/config/features',
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
                        'description' => 'Визуальная карта зала: spaces, размещение ПК / TV / PS5. Сохраняет map_config клуба и синхронизирует computers и spaces. Комната без привязанного ПК/ТВ на карте рисуется на 90% прозрачной с подписью SERVICE.',
                        'path' => '/admin/map-builder',
                        'audience' => 'Supervisor+',
                    ],
                    [
                        'title' => 'Shell-оверлеи',
                        'description' => "Экранные блоки терминала (картинка / видео / текст) на /admin/overlays. Shell забирает активные оверлеи по позициям GET /api/shell/overlays.\n\nПока Clan War live, в тот же JSON добавляется clan_war, а слоты DAT mid_left / mid_right рисуют счёт сторон (роль clan_war). Idle TV поллит оверлеи ~4 с вместо 45 с. Не путать с ручными текстовыми слоями — live-счёт пишет ClanWarService::paintOverlayBlocks.\n\nАрена: в тот же JSON кладётся arena_duel (тикер дуэли / победителя). TV Shell рисует ArenaDuelBanner.",
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
                        'description' => "Правка золотого образа — с игрового ПК (Shell setup) или очередью из админки: POST /admin/api/computers/diskless → шелл в ответе heartbeat включает CCBoot Client. Пароль — Diskless/admin_password в config.ini на месте, не в облаке. Один Super Client на клуб; гость на месте блокирует команду; диск image по умолчанию, game/both — отдельное подтверждение.\n\nПошагово локально: «Обслуживание образа: setup и Super Client» в разделе Shell.\n\nНе реализовано: агент на сервере .10, refresh cache кнопкой, смена VHD, BitTorrent.\nЕсть Rollback Markers — хэши манифестов/конфигов при save Super Client и откат на проверенную ревизию с дашборда (фича /admin/config/features). Не откат VHD CCBoot: для отравленного драйвера Windows техник берёт restore point CCBoot той же даты, что маркер.\nЕсть LAN P2P-кэшер патчей — полная глава в Shell: «LAN P2P-кэшер патчей (Steam/Epic)» (seed :8745, peer patch_pull по buildId без WAN).\n\nСеть под бездиск — «Сеть клуба»: VLAN 20 общий L2 сервер+ПК, 2×10G LACP на CRS354, DHCP только один источник. Админка оркестрирует лицензии/WOL/Super Client на клиенте, не PXE.",
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
                        'title' => 'ПК лиц',
                        'description' => 'Служебный GPU-ПК в зале: метки NVR, лица по субпотокам, ComfyUI для аватара. На 12 ГБ — SDXL Lightning + InsightFace на CPU, не FLUX параллельно. Три службы NSSM (marker / face / comfy). ТЗ — раздел «Сеть клуба»: главы «ПК лиц». Не бездиск .10 и не игровое место. Облако на :8188 не ходит — только HTTPS наружу, как у агента меток.',
                        'path' => '/admin/docs',
                        'audience' => 'Техник / Supervisor+',
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
                        'description' => "По этой главе собирается LAN клуба с нуля. Booking в облаке в розетки не ходит — только HTTPS с площадки наружу.\n\nЖелезо:\n• Роутер MikroTik RB5009UG+S+IN (7×1G + 1×2.5G + 1×10G SFP+) — WAN, NAT, VLAN, hotspot, WOL, isolate. Не коммутатор зала, без контейнеров.\n• Свитч MikroTik CRS354-48G-4S+2Q+RM (48×1G + 4×10G SFP+ + 2×40G QSFP+) — только switching. NAT/DHCP на CRS не включать.\n• Бездиск: сервер + NIC LR-LINK LREC9812AF-2SFP+ (2×10G SFP+). В стойке DAC 10G SFP+, не оптика.\n• 5× Dahua DH-CS4010-8ET2GT-110 (8×100M PoE + 2×1G uplink, 110 Вт) — камеры.\n• NVR Hikvision DS-7764NI-M4; до 40× HiWatch DS-I402(D) 4 Мп 2.8 мм.\n• Игровые ПК (до ~40 на одном CRS354, гигабит в медь); ПК лиц (ether47, свой диск, GPU); касса; ТВ/PS; принтер кухни.\n\nПорядок включения: 1) стойка и кабель 2) VLAN/IP 3) CRS354 4) RB5009 5) бездиск 6) игровые ПК 7) камеры/NVR 8) агенты (WOL, кухня, метки, ПК лиц). Не включать бездиск, пока LACP 10G не линкуется. ПК лиц не PXE — локальная Windows, иначе вместе с залом гаснут метки и лица.",
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
                        'description' => "Регистратор DS-7764NI-M4 на ether46, IP 192.168.222.12, маска /24, шлюз .1 (NTP). Конфиг системы → Сеть → ISAPI вкл; HTTP(S) :80 Digest. Запись на HDD NVR, не на бездиск.\n\nКамеры HiWatch DS-I402(D) 4 Мп 2.8 мм (~99°), H.265+, PoE ~6.5 Вт, порт 100 Мбит, IP67. Родные для Hikvision. Лиц в камере нет — субпоток (H.264, CIF/D1, 8–15 к/с, канал камеры 1 = Streaming/Channels/102) на ПК лиц, см. главу «ПК лиц». Основной 4 Мп (101) только в NVR. Битрейт 2–4 Мбит, не 8. 40 шт. ≈ 80–160 Мбит при лимите NVR ~400.\n\n5× CS4010: по зонам (вход, зал1, зал2, бар, улица). Порт 9 каждого → ether41–45 CRS354, VLAN 30. Не гирлянда Dahua→Dahua. 8×6.5 Вт ≈ 52 из 110 Вт. PTZ только порты 1–2. Extend 250 м выкл. DoLynk выкл.\n\nДобавление: NVR «Доступ к устройству» / Plug and Play в VLAN 30, протокол Hikvision. Пароли камер не дефолт admin/12345.\n\nНа 64 канала — ещё PoE-свитчи в те же ether/второй CRS, ядро не менять.",
                        'path' => null,
                        'audience' => 'Техник',
                    ],
                    [
                        'title' => 'Видео-метки: зачем и куда смотреть',
                        'description' => "Закладка на записи NVR, чтобы не мотать 40 каналов: HID (мышь/клава), SOS или Rage-Smash → флажок с текстом «HID · PC-08» / «Rage-Smash · ПК-12». Это не иконка лица/машины и не «Событие AIOP».\n\nИскать: Воспроизведение → камера (канал из админки, 1 = D1) → шкала; либо бэкап/поиск по тегу.\n\nТриггеры: hid.disconnected / hid.device_changed / hid.unstable (POST /api/shell/hid/alert), sos (POST /api/shell/sos), hardware.abuse (POST /api/shell/incidents type=hardware_abuse — метка 1 с + 1 с pre), store.assembly_start / store.assembly_done (магазин, камера стола сборщика), тест в админке. События — /admin/video-surveillance; при первом срабатывании недостающее событие создаётся само. Канал = номер камеры NVR (1 → track 101). ПК зала→камера пока нет; стол сборки — STORE_ASSEMBLY_NVR_CHANNEL или канал события.",
                        'path' => '/admin/video-surveillance',
                        'audience' => 'Supervisor+ / Админ / Техник',
                    ],
                    [
                        'title' => 'Видео-метки: агент на ПК лиц',
                        'description' => "Облако на NVR не ходит. Очередь video_surveillance_marker_jobs → агент scripts/hikvision-marker-agent.ps1 на ПК лиц (VLAN 20 + доступ к 192.168.222.12). Не бездиск, не RB5009. Тот же ПК, что лица и ComfyUI — главы «ПК лиц» ниже.\n\nАгент: GET /api/video/marker-targets?token=… → Digest PUT …/recordTag + lock → POST /api/video/marker-applied. Тот же процесс: GET /api/video/assembly-clip-targets → ffmpeg RTSP playback стола сборщика → POST /api/video/assembly-clips (нужен ffmpeg в PATH). Env: VIDEO_API_BASE, VIDEO_MARKER_TOKEN (= VIDEO_MARKER_RELAY_TOKEN или CLUB_WOL_RELAY_TOKEN). Служба NSSM club-marker-agent (Restart on crash 5–10 с), не общий Python с лицами и ComfyUI.\n\nАдминка: вкл, провайдер Hikvision NVR, URL http://192.168.222.12, admin + пароль NVR, канал стола сборки, path пустой. События «мышь», «SOS», шаблоны «сборка ПК» / «ПК готов». Тест без агента только копит очередь.\n\nHik-Connect, ISUP (Ehome), OTAP, HEOP/AIOP — не включать, к меткам не относятся. NVR в интернет не пробрасывать.",
                        'path' => '/admin/video-surveillance',
                        'audience' => 'Техник / Supervisor+',
                    ],
                    [
                        'title' => 'ПК лиц: ТЗ железа и место в сети',
                        'description' => "Зачем. Одна служебная машина в клубе: (1) агент меток NVR и нарезка ролика сборки — уже в продукте; (2) распознавание лиц по субпотокам камер — ТЗ, в камере лиц нет; (3) ComfyUI FaceID для галки «стилизовать аватар» — ТЗ очереди, облако до GPU само не ходит. Не игровой ПК, не бездиск 192.168.20.10, не RB5009.\n\nЖелезо (минимум):\n• Свой диск, не PXE. Windows 11 Pro 24H2 x64, локальная учётка службы, автологon в сессию агентов.\n• CPU ≥ 6 ядер (Ryzen 5 / Core i5), RAM 32 ГБ (16 ГБ тесно, когда лица + SDXL).\n• NVIDIA 12 ГБ VRAM и выше (RTX 3060 12GB минимум; 4060 Ti 16 / 4070 спокойнее). 8 ГБ — либо лица, либо ComfyUI, не оба. На ровно 12 ГБ FLUX (даже FP8/GGUF + T5xxl + CLIP L + PuLID = 11–14 ГБ) параллельно с InsightFace buffalo_l и живым RTSP-декодом = CUDA OOM; ComfyUI тогда только SDXL Lightning/Turbo (или компактный SDXL/1.5 FP16) с --lowvram. FLUX — 16+ ГБ и InsightFace не на CUDA. Драйвер Game Ready / Studio, не драйвер бездиска зала.\n• NVMe ≥ 1 ТБ: модели InsightFace + SDXL (FLUX только если карта 16+) + IP-Adapter/PuLID ≈ 50–80 ГБ, запас под кэш.\n• Один гигабит в ether47 CRS354, access VLAN 20. Второй NIC не обязателен: на NVR ходим через RB5009, не прямым кабелем в VLAN 30.\n• Статика или DHCP-резерв по MAC: 192.168.20.47/24, шлюз .1, DNS роутер. Имя хоста FACE-01.\n• UPS вместе со стойкой (роутер/свитч/NVR). Корпус в подсобке/ресепшен, не в зале гостей.\n\nСеть (RB5009):\n• VLAN 20 → WAN: HTTPS к APP_URL (booking), NTP, драйверы NVIDIA, Hugging Face только если модели качаем руками — в работе лиц/аватара WAN GPU не нужен.\n• С 192.168.20.47 (этот MAC/IP) разрешить 192.168.222.12:80 и :554 (ISAPI + RTSP субпотока). С игровых .100–.199 на NVR — запрет, как в главе VLAN.\n• VLAN 30 ↛ WAN. DST-NAT 8188/80/554 на ПК лиц и NVR — не делать. ComfyUI слушает только 127.0.0.1.\n• RDP/Winbox на FACE-01 только с VLAN 10/mgmt, не с гостевого Wi-Fi.\n\nНе ставить сюда: CCBoot-сервер, кухню ESC/POS, Steam-кэш зала, антивирус-сканер всех VHD в час пик.",
                        'path' => null,
                        'audience' => 'Техник',
                    ],
                    [
                        'title' => 'ПК лиц: софт (метки, лица, ComfyUI)',
                        'description' => "Порядок установки на FACE-01:\n1) Windows, имя FACE-01, статика .47, ping 192.168.20.1 и https://APP_URL, с этого ПК открывается http://192.168.222.12 (веб NVR), с игрового — нет.\n2) NVIDIA драйвер, nvidia-smi видит карту и 12+ ГБ.\n3) ffmpeg в PATH (сборка full) — уже нужен агенту меток для ролика стола сборки.\n4) curl.exe — штатный Windows, HTTP Digest к NVR.\n5) Git, Python 3.11 x64, CUDA toolkit под драйвер.\n6) scripts/hikvision-marker-agent.ps1 как служба NSSM club-marker-agent (Restart on crash, 5–10 с), не один планировщик «при входе» вперемешку с Python. Env user-level: VIDEO_API_BASE=https://APP_URL, VIDEO_MARKER_TOKEN=тот же VIDEO_MARKER_RELAY_TOKEN / CLUB_WOL_RELAY_TOKEN, VIDEO_POLL_SECONDS=3. Админка /admin/video-surveillance уже отдаёт IP/логин NVR в payload. Проверка: «Тест метки» → тег на шкале D1; в логе агента poll OK.\n7) Лица (ТЗ, пайплайн в booking ещё не в проде). Служба NSSM club-face-agent, отдельно от меток и ComfyUI. Захват: cv2.VideoCapture RTSP субпотока NVR (H.264, 640x360 / 704x576), не основной 4 Мп. Камера 1 → Channels/102, камера 2 → 202 (субпоток N02, не основной N01). Логин/пароль NVR — из админки (как у агента меток), не хардкод в репо. Путь: rtsp://USER:PASS@192.168.222.12:554/Streaming/Channels/102. Инференс только если frame_id % 5 == 0 (пропуск 4 из 5) — ~3–5 FPS и 5–8% CPU на 2–3 субпотоках.\nБоевой FaceAnalysis — жёстко CPU, CUDAExecutionProvider в providers нет (иначе снова VRAM с ComfyUI). gpu_mem_limit на CUDA в этом агенте не использовать:\nfrom insightface.app import FaceAnalysis\napp = FaceAnalysis(name='buffalo_l', providers=['OpenVINOExecutionProvider', 'CPUExecutionProvider'])\napp.prepare(ctx_id=-1, det_size=(640, 640))\nbuffalo_l = SCRFD det_10g + ArcFace w600k_r50. Эталон: сотрудники (пересменка «взгляд в камеру») и по желанию гости из ЛК. Не писать лица в облако как ролик — только вектор/событие.\n8) ComfyUI: отдельная папка C:\\ComfyUI, служба NSSM club-comfy-worker. Запуск python main.py --listen 127.0.0.1 --port 8188 --disable-metadata. Не --listen 0.0.0.0. На 12 ГБ: --lowvram и чекпоинт SDXL Lightning / Turbo (или компактный SDXL/1.5 FP16); FLUX + PuLID на 12 ГБ с живым декодом и лицами — запрет (OOM). 16+ ГБ: FLUX допустим, если InsightFace на CPU. Custom nodes IP-Adapter FaceID или PuLID только под выбранный чекпоинт. Граф Save (API Format) → C:\\club-agent\\avatar_workflow.json: первый LoadImage = фото игрока, второй = образец public/images/avatars/avatar_N.png. Чекпоинт на диске, не качать при каждом запросе. nvidia-smi не должен показывать «чужой» майнер.\n9) Не ставить Automatic1111 параллельно на тот же порт. Один GPU-сервер — один ComfyUI. Три службы — глава «ПК лиц: VRAM и три службы».",
                        'path' => null,
                        'audience' => 'Техник',
                    ],
                    [
                        'title' => 'ПК лиц: VRAM и три службы',
                        'description' => "Конкуренция GPU. Заявлено 12+ ГБ, 24/7 детекция лиц + фоновый ComfyUI. Это не «хватит карты», а очередь на одни CUDA-ядра и одну VRAM.\n\nOOM. FLUX dev/schnell даже FP8/GGUF вместе с T5xxl + CLIP L и весами PuLID занимает 11–14 ГБ. Если buffalo_l уже держит det_10g (SCRFD) и w600k_r50, шаг ComfyUI падает CUDA Out of Memory.\nНа 12 ГБ (3060 / 4070 12GB): ComfyUI строго SDXL Lightning / Turbo или компактный SDXL/1.5 FP16, флаг --lowvram (лица на CPU) либо --gpu-only только если InsightFace снят с CUDA. FLUX параллельно с живым видеодекодом и InsightFace на 12 ГБ не запускать.\n\nПросадка FPS. K-Sampler утилизирует CUDA ~100%. Инференс InsightFace на GPU в этот момент с 15–20 мс/кадр скачет до 400–900 мс — дыра на входе/пересменке.\nКупирование (боевой club-face-agent). InsightFace только CPU: FaceAnalysis(name='buffalo_l', providers=['OpenVINOExecutionProvider', 'CPUExecutionProvider']); app.prepare(ctx_id=-1, det_size=(640, 640)). CUDAExecutionProvider и gpu_mem_limit в этот процесс не класть. Захват cv2.VideoCapture на субпоток NVR (камера 1 = Channels/102, не 101). Инференс при frame_id % 5 == 0 (пропуск 4 из 5). 2–3 субпотока, ~3–5 FPS, 5–8% CPU на i5 12-gen / Ryzen 5000+ — GPU целиком ComfyUI.\n\nТри службы, не один процесс. NSSM, Restart on crash, задержка 5–10 с (или три задачи Планировщика — хуже, NSSM канон):\n1) club-marker-agent → scripts/hikvision-marker-agent.ps1 (curl/ffmpeg). Критичен для админки меток и ролика сборки.\n2) club-face-agent — Python, RTSP + InsightFace, непрерывно.\n3) club-comfy-worker — очередь stylize-targets + процесс ComfyUI.\nПадение ComfyUI по таймауту или утечке Python не роняет простановку меток на NVR и не срывает фиксацию пересменки. Общий лог C:\\club-agent\\logs, общая учётка службы, разные процессы.",
                        'path' => null,
                        'audience' => 'Техник',
                    ],
                    [
                        'title' => 'ПК лиц: подключение к бэкенду',
                        'description' => "Правило клуба: booking в розетки не ходит. FACE-01 сам ходит HTTPS наружу и pull’ит очереди — как метки, кухня, WOL. COMFYUI_URL=http://192.168.20.47:8188 в облачном .env бесполезен (и дыряв, если пробросить). COMFYUI_URL на сервере PHP имеет смысл только если GPU стоит рядом с Laravel, у нас не так.\n\nУже есть (метки, тот же хост):\n• GET /api/video/marker-targets?token=… → работа на NVR → POST /api/video/marker-applied\n• GET /api/video/assembly-clip-targets → ffmpeg RTSP → POST /api/video/assembly-clips\nТокен: VIDEO_MARKER_RELAY_TOKEN или CLUB_WOL_RELAY_TOKEN. База: VIDEO_API_BASE = APP_URL без хвоста.\n\nТЗ аватар (очередь, агент ещё писать). Игрок POST /account/profile/avatar + stylize=1. Облако сразу клеит лицо в овал avatar_*.png (гость не ждёт GPU) и кладёт job. Агент на FACE-01:\n• GET /api/avatar/stylize-targets?token=… (тот же relay-токен или отдельный AVATAR_RELAY_TOKEN)\n• payload: job_id, photo_url или bytes, sample_url (текущий клубный шаблон), prompt\n• POST http://127.0.0.1:8188/upload/image + /prompt, poll /history/{id}, GET /view\n• POST /api/avatar/stylize-applied { job_id, image = PNG } → users.avatar = custom/u{id}_….png, старый custom удалить\n• ошибка/таймаут ~90 с → applied с status=failed, в профиле остаётся локальный композит, не «пусто»\nПока этих маршрутов нет — стилизация в проде = локальный композит (± HF/OpenAI, если ключи на облаке). Не ждать GPU, чтобы сохранить фото.\n\nТЗ лиц (события, ещё писать). club-face-agent: FaceAnalysis buffalo_l на CPU (OpenVINOExecutionProvider, затем CPUExecutionProvider; ctx_id=-1; det_size 640). RTSP субпоток Channels/102…, кадр каждый пятый. При уверенном match:\n• POST /api/face/events { camera, track, embedding_id or staff_id/user_id, ts, score } — пересменка, вход в зал, тикет «чужой у стойки». Эталоны персонал тянет pull’ом (не выкладывать биометрию в публичный JSON без токена). Ролики лиц в облако не слать.\n\nОбщий агент — нет. Три службы NSSM: club-marker-agent, club-face-agent, club-comfy-worker (глава «ПК лиц: VRAM и три службы»). Watchdog: Restart on crash 5–10 с. Heartbeat раз в 30 с (онлайн FACE-01 на дашборде) — в ТЗ, плитки ещё нет. Секрет токена только на FACE-01 и в .env облака, не в образе игровых ПК.\n\nПриёмка техника (FACE-01):\n• Изоляция NVR: с игрового ПК curl -I http://192.168.222.12 → Connection timed out / No route to host.\n• Порт ComfyUI: с 192.168.20.10 curl -I http://192.168.20.47:8188 → Connection refused (слушает только 127.0.0.1).\n• Конкуренция GPU: генерация аватара при живом потоке лиц — nvidia-smi без OOM, поток лиц без CUDA error.\n• Рестарт хоста: shutdown /r /t 0 на FACE-01; через 2 мин маркер ставится, face-события в логе, ComfyUI отвечает на 127.0.0.1:8188.\n• Таймаут воркера: сломанный граф в очередь — через ~90 с status=failed, в профиле остаётся локальный композит (fallback), не «пусто».\n• Метка с админки на D1; curl 127.0.0.1:8188/system_stats с самой машины ок; с игрового нет веб NVR.",
                        'path' => '/account/profile',
                        'audience' => 'Техник / Система',
                    ],
                    [
                        'title' => 'Чеклист приёмки сети',
                        'description' => "Ядро: ping 192.168.20.1 с ПК; Winbox на RB5009 и CRS354; sfp-sfpplus1–2 LACP Up, без дискards.\nБездиск: PXE одного ПК → образ; 5 ПК разом — загрузка без таймаута; iperf/копирование с .10 saturates >10 Гбит суммарно на bond.\nИгры: ПК в интернете; с игрового ПК не открывается http://192.168.222.12; WOL с дашборда будит выключенный клиент.\nКамеры: 40 online в NVR; запись 24/7; с ПК лиц открывается веб NVR; с игрового — нет.\nМетки: агент в логе poll OK; «Тест метки» → тег на D1; HID с тестового ПК → тег.\nПК лиц: хост FACE-01, 192.168.20.47, nvidia-smi 12+ ГБ; ComfyUI только 127.0.0.1:8188; с 192.168.20.10 curl -I http://192.168.20.47:8188 → Connection refused; с игрового curl -I http://192.168.222.12 → timeout / no route; генерация аватара при детекции — без CUDA OOM; shutdown /r /t 0 → через 2 мин метки, face-лог, 127.0.0.1:8188 живы; сломанный граф → 90 с status=failed и fallback-аватар; три службы NSSM (marker / face / comfy).\nОтказ: выключить RB5009 — зал и бездиск продолжают грузиться друг у друга (L2), интернет и WOL пропадают. Выключить CRS354 — встаёт всё. Выключить бездиск — ПК не грузятся, камеры живы. Выключить FACE-01 — метки и лица встают, зал играет.",
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
                        'description' => "Единая лента /admin/incidents: late_order, low_stock, расхождения склада, SOS, HID, ручные записи, shell-инциденты (fan_bearing_wear, golden_image_drift, golden_image_crash — «Синий экран / сбой драйвера», nic_link_flap — «Заменить патч-корд на ПК-ХХ», hardware_switch_fault — «Проверить свитч/микрик на ПК-ХХ», hardware_abuse — «Удар по столу на ПК-ХХ»).\n\nПлан: домашние кики/баны античита — /admin/fair-play и раздел «Античит домашней игры (REACTOR AC)», в этой ленте зала ещё нет.\n\nТипы с ПК: POST /api/shell/incidents (ShellIncidentService, dedupe по computer_id+type пока не закрыт). Link flap ≥2 за смену пишет nic_link_flap из heartbeat (см. «Деградация кабеля» в Shell). Дребезг мыши / залипание клавиши — Hardware Health поверх HID-сессии (глава Shell). Rage-Smash (IMU/key-mash + K/D) — hardware_abuse и секундная метка NVR (глава Shell). BSOD — Rollback Markers, кнопка «Откатить образ».\n\nAck и закрытие (resolve — supervisor+).",
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
                        'description' => 'Снимки периферии и алерты: смена / отключение / нестабильность устройств. Триггеры hid.disconnected / hid.device_changed / hid.unstable ставят видео-метки на NVR (событие создаётся само, если его не завели в /admin/video-surveillance).\n\nПоверх той же HID-сессии шелл (HardwareHealthWatchdog) смотрит физические события ввода: дребезг микрика мыши (фантомный дабл-клик) и залипание/дребезг скан-кода. Это не computer_input_alerts, а тикет hardware_switch_fault «Проверить свитч/микрик на ПК-ХХ» в этой ленте. Удар по столу / key-mash — Rage-Smash (hardware_abuse), отдельная глава Shell.',
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
                        'description' => "Баланс, активные брони с таймером, заказы магазина, транзакции, прогресс достижений, статус заявки на бонус за отзыв.\n\nБлок Clan Wars (если есть live или рейтинг): текущий счёт сторон, свой Elo по фракциям, таблица кланов. Подробности — «Clan Wars» в Киберспорте / Shell.\n\nВ шапке на мобильных/планшетах — иконка QR-сканера (на десктопе скрыта: вход с телефона). См. «Вход по QR».\n\nКнопка «Сесть за ПК» без живой сессии — заготовка быстрого входа (логика «Подключиться» ещё заглушка). При активной сессии кнопка становится «Пересесть» (см. «Пересадка на другой ПК»).\n\nИгра с дома на сервер клуба: план установщика античита и kick без клиента — раздел «Античит домашней игры (REACTOR AC)». Скачать пока нечего.",
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
                        'description' => "Редактирование никнейма, email и фото (ЛК /account/profile и карточка аватара в кабинете). Телефон зафиксирован как идентификатор входа. Стартовый ник при первой регистрации выдаёт DeepSeek (см. «Игровой ник»).\n\nСвоё фото: POST /account/profile/avatar. Галка «Стилизовать под клубный формат». DeepSeek картинки не рисует.\n\nСейчас в проде: лицо с фото в овал стандартного avatar_*.png (броня и схемы шаблона остаются). Если на облаке есть HF_TOKEN / OpenAI — дорисовка композита. COMFYUI_URL с облака до клуба не достучится.\n\nЦелевая схема (ТЗ): GPU на ПК лиц в зале, ComfyUI на 127.0.0.1:8188, агент pull’ит очередь стилизации и шлёт PNG обратно. Железо, софт и API — раздел «Сеть клуба», главы «ПК лиц». Пока очереди нет, гость сразу получает локальный композит.",
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
                        'description' => "Публичная карта клуба, зоны, тарифы, availability ПК и игр, расчёт цены, бронь с PIN. Есть киоск `/terminal`.\n\nКомната без привязанного ПК/ТВ — заливка 90% прозрачная и бейдж SERVICE.\n\nПати: в сайдбаре «Сесть рядом» выбирает N свободных мест подряд в одной зоне (type + space_id, по номеру в имени ПК). Оплата как у обычной мультиброни (один BookingGroup, один PIN) — отдельной ссылки сплит-чека нет.",
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
                        'description' => "Зачем. Гибрид: один Windows-образ на все игровые ПК + SSD/NVMe в каждом как кэш. Обычная загрузка пишет изменения гостя в writeback. После reboot writeback выкидывается — Windows, драйверы, сам Shell, лаунчеры на C: как были в образе. Без Super Client патч Valorant, обновление шелла или драйвер GPU на одном ПК до следующего ребута живёт и на остальные 39 не попадает.\n\nSuper Client (CCBoot / CCBoot Cloud) говорит серверу: этот ПК сейчас редактирует выбранный диск. Пока режим включён, запись идёт в образ (или игровой диск), а не в одноразовый writeback. Выключили Super Client и сохранили — золотой образ обновлён, остальные ПК получат его с кэша/сервера.\n\nПочему не только из booking. Пароль Admin Password CCBoot живёт на клиенте (config.ini Diskless/admin_password) или вводится в setup. Облако очередь команд, не PXE и не хранилище пароля. Без пароля в ini удалённая кнопка вернёт no_password.\n\nУдалённо с дашборда. Клик по месту → Super Client (диск image/disk/both) или «Выкл + save». POST /admin/api/computers/diskless. Шелл забирает diskless из ответа /api/shell/power/heartbeat (~30 с), ставит maintenance, жмёт CCBoot Client. Гарды: ПК онлайн, нет active-сессии, не второй Super Client в клубе. Game disk — два подтверждения: том лочится.\n\nПочему из шелла, а не из booking. Админка лицензий/WOL образом не управляет (нет API CCBoot в облаке). Техник стоит у места: Win+ПКМ → setup → включить Super Client на этом клиенте. Пароль — Admin Password CCBoot (General Options на сервере бездиска), не PIN брони и не пароль кассы.\n\nКакой диск. В setup: image | disk | both.\n• image — ОС, Shell, Visual C++, лаунчеры, античиты в Program Files на системном томе. Так делают почти всегда.\n• disk — игровой том (Steam library и т.п.). Youngzsoft не советует держать Super Client на game disk у всех ПК: том лочится на сервере, остальные грузят игры медленнее, риск порчи. Игры лучше катить с сервера или с локального SSD-кэша без SC на game disk.\n• both — только если сознательно правите и ОС, и игровой диск с одного клиента.\nПо умолчанию: image.\n\nКогда нельзя. Час пик зала (остальные грузятся дольше). Super Client уже висит на другом ПК с тем же образом. Мало места на image disk сервера (нужен запас 10–20 ГБ). Сессия гостя на этом месте — сначала logout. Не обновлять драйвер LAN/NIC в Super Client — сломает PXE/iSCSI.\n\nЧто должно быть в образе заранее. C:\\CCBootClient\\CCBootClient.exe (или путь в config.ini [Diskless] client_exe=). [Security] production=true. iCafeMenu / iCafeCloud cafe-оболочку не ставить — шелл клуба это REACTOR. [Diskless] server_ip=192.168.20.10 справочно.\n\nАлгоритм включить и править\n1) ПК в idle: экран логина GUEST, гостя нет. Сессию закрыть.\n2) Открыть setup (REACTOR CONTROL), не путать с паузой и reboot-PIN гостя:\n   • Win+ПКМ на экране логина;\n   • если киоск съел Win (NoWindowsKey) — Ctrl+ПКМ или Ctrl+клик по имени ПК (TERMINAL_ID).\n3) Блок «ОБРАЗ · SUPER CLIENT». Поле пароля = Admin Password CCBoot. Диск = image.\n4) «Включить Super Client». Шелл снимает киоск (unlockSystem + explorer), прячет себя, запускает CCBootClient.exe и жмёт Enable Super Client / тип диска / пароль / reboot. Если диалоги не поймались — «Только CCBoot Client» и те же кнопки руками в окне Youngzsoft.\n5) ПК уходит в reboot. Это норма: Super Client применяется со следующей загрузки.\n6) После reboot шелл видит флаг Super Client и киоск не ставит (explorer доступен). Снова Win+ПКМ в setup, если нужен UI шелла; либо работайте с рабочего стола.\n7) Правки: Windows Update, GPU-драйвер (не LAN), новый билд шелла, лаунчер, античит в образ, программы в Program Files. Игры, которые должны жить на SSD/игровом томе, не тащите на C: без нужды.\n8) Проверка на ЭТОМ ПК: игра/шелл стартуют. Не выключайте ПК кнопкой питания в середине записи образа.\n\nАлгоритм сохранить и выйти\n9) Снова setup → «Выключить и сохранить» (Disable Super Client + save). Пароль тот же. CCBoot спросит: сохранить образ? Да. Restore point? Да, с короткой пометкой (дата + что меняли). Затем shutdown клиента. Шелл в этот момент архивирует Rollback Marker (хэши манифестов Steam/Epic и конфигов) — глава «Rollback Markers золотого образа».\n10) На сервере бездиска / в iCafeCloud PC больше не красный Super Client. Образ записан. Остальные места: следующий boot или refresh кэша — уже новая ревизия.\n11) Проверка на втором ПК (не том, на котором только что писали, если кэш ещё старый): логин шелла, запуск одной игры. Если второй ПК встал на старый кэш — refresh cache этого клиента в CCBoot, не «ещё раз Super Client на всех».\n12) Не оставляйте Super Client включённым «на потом»: writeback выключен, зал тормозит, game disk может быть locked.\n\nТолько киоск, без Super Client. Кнопка «Снять киоск» даёт explorer здесь и сейчас. После обычного reboot всё откатится. Так смотрят логи, не так обновляют золотой образ.\n\nНе путать\n• Pause / «пин после перезагрузки» на дашборде — PIN гостя, чтобы войти в ту же бронь. Образ не трогает.\n• Super Client с консоли iCafeCloud (ПК → Enable superclient) — тот же режим, если кнопки в CCBoot Client нет (ветка Cloud). Итог тот же: reboot → правки → Disable + save.\n• Booking /admin/licenses — пул Steam-аккаунтов, не смена VHD.\n\nКод: SetupScreen.qml блок Super Client; CcbootSuperClient; SecurityManager::unlockSystem; вход Main.qml openSetupScreen (Win/Ctrl+ПКМ). Документ сети: VLAN 20, сервер .10 — глава «Бездисковый сервер».",
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
                        'description' => 'Периодический опрос баланса. При опросе закрываются просроченные сессии; remaining считается согласованно с кабинетом (wall-clock / heal ends_at). В том же ответе — bounties, party_energy, ghost_coach, throne, lfg, lootbox, arena.',
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
                        'title' => 'LAN Arena (дуэли без ставок)',
                        'description' => "Клубный 1v1 / битва без денег игроков: выяснить, кто лучше стреляет, разминка перед FACEIT/Премьером, короткий матч 3–5 минут. Не пари, не касса, не эскроу и не ст. 1057. Рейтинг Elo, зал славы, звание «Босс клуба», царь горы за вечер. Перк (энергетик или бесплатный час) — подарок заведения первому, кто наберёт серию побед за календарный день; один перк на клуб на вечер. Трон ПК (pc_throne, фраги на месте) — отдельная фича.\n\nДва режима: дуэль (двое, старт после акцепта) и битва (3–N, лобби открыто до старта / набора / времени). Вызов из шелла или заранее из ЛК без активной сессии. Открытые вызовы — карточки в ЛК и шелле (время, игра, Elo автора). Принять не списывает депозит. Ставки / raise отключены.\n\nФича /admin/config/features → «Арена дуэлей»: TTL адресного и открытого вызова, TTL из ЛК, мин/макс битвы, серия до перка царя горы, бесплатные минуты, сначала энергетик, допуск ранга LFG, таймаут дисконнекта, кулдаун спама, first-to CS2, ваучер-поздравление на кухню.\n\nШелл: вкладка «АРЕНА», POST /api/shell/arena/challenges {kind: duel|battle, game, mode: 1v1_aim|2v2_wingman|1v1_mid, scope: hall|computer|zone, target_computer_id, scheduled_at, max_players}. Акцепт/отклонение/отмена/старт: /accept /decline /cancel /start. /raise и /raise-vote отвечают 422. Доска GET /api/shell/arena/live и lan-live/balance (ladder, week, boss, koth, me). GSI POST /api/shell/gsi: round_win до first_to или match_win при живом GSI соперника. 2v2 — пати из ровно двух активных ПК. Ранг: последний LFG той же игры, неизвестный не блокирует. Дисконнект 90 с при живом оппоненте — техническое поражение. Админ: POST /admin/api/arena/duels/{id}/force-refund снимает матч (supervisor+).\n\nДомашний игрок на дедик: одноразовый password=connect_token, не общий пароль лобби — раздел «Античит домашней игры (REACTOR AC)». Пока gate выкл, ArenaDuelService ещё клеит статический password.\n\nЛК: GET /account/arena/live, POST /account/arena/challenges без сессии на ПК. Карточки hall, входящий вызов, зал славы, «Вызвать босса» если босс сидит в клубе. TV: arena_duel в GET /api/shell/overlays — live-матч, иначе царь горы / босс клуба.\nКод: ArenaDuelService, ArenaRating, ArenaKothEvening. Тесты: ArenaDuelsTest.",
                        'path' => '/admin/config/features',
                        'audience' => 'Shell / Игрок / Supervisor+',
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
                        'description' => "Если бронь в BookingGroup на 2+ ПК (мультибронь друзей или LFG-матч в зале): общий котёл минут. Капитан POST /api/shell/party/energy/auto-fuel включает бесшовную подпитку. Любой из пати кладёт минуты POST /api/shell/party/energy/contribute (с депозита или со своей сессии). Когда у участника <90 с и GSI говорит in_match, сессия не выбивается в паузу: шелл держит игру, сервер сифонит 10 мин из котла (и на completeExpiredSessions). Без согласия капитана и пустой котёл — обычный logout.\n\nЭкономика CS$ пати — не котёл, а Coach Whisper (эко-раунд / дроп AWP) поверх Ghost Coach.",
                        'path' => null,
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Ghost Coach (ИИ-тактик)',
                        'description' => "GSI на шелле работает всю сессию. POST /api/shell/gsi + экономика/ульт. Шаблоны: «У вражеского Enigma на ПК-14 готов Black Hole» / «У них эко, жди раш с дробовиками» / AWP на линии. Если шаблон молчит — короткий LLM (DeepSeek, 4 с) по снимку GSI зала. Шёпот раз в ~28 с, галка в шелле (POST /api/shell/coach). F1 hold-to-talk дополнительно получает строку [GSI] в промпт (микрофон + live state). Пуш в наушники через SAPI/TTS.\n\nПати (BookingGroup): см. «Coach Whisper: Eco-Round & Drop Synchronizer» — общий банк команды и синхронный шёпот эко/дропа AWP.",
                        'path' => '/admin/ai-assistant',
                        'audience' => 'Shell',
                    ],
                    [
                        'title' => 'Coach Whisper: Eco-Round & Drop Synchronizer',
                        'description' => "Расширение Ghost Coach, только CS2 и только пати: бронь в BookingGroup на 2+ ПК (мультибронь «сесть рядом» или LFG в зале). Соло-сессия этот канал не включает — остаётся обычный Ghost Coach.\n\nОткуда деньги. GSI CS2 на каждом ПК шлёт player.state.money (allplayers у Valve нет). POST /api/shell/gsi кладёт снимок в ShellGsiStore (TTL 30 с). Облако склеивает кошельки всех computer_id группы, та же сторона (team T/CT), in_match. Нужно ≥2 живых банка. Срабатывает в freeze (event=freezetime или phase=freezetime) — пока ещё можно сейвить или скинуть AWP.\n\nЭко-раунд. Сумма $ пати < N × 2000 (N — число тиммейтов с деньгами в снимке; порог тот же, что личное эко Ghost Coach). Одна фраза на всю команду: «Эко-раунд, копим на бай». Личные «у вас эко / фуллбай» в этот freeze глушатся, чтобы не спорить с командным решением.\n\nДроп AWP. Если эко нет, и у одного избыток (≥5500$ — хватает на AWP 4750 плюс запас), а у союзника нет AWP в руках и банка <4750$ — «Скинь AWP на ПК-05» (имя computers.name получателя, обычно беднейший без AWP). Эко важнее дропа: при нищем общем банке AWP не предлагается.\n\nСинхрон. Первое место, у которого набралось ≥2 кошелька, считает фразу и кладёт её в кэш coach:party:{group}:{match}:{round} на 18 с (окно freeze). Остальные POST /api/shell/gsi той же пати в том же раунде получают ту же строку — даже если их $ чуть разъехались. Повтор на том же ПК в том же раунде глушится (heard-once). Дальше кулдаун Ghost Coach (по умолчанию 28 с, /admin/config/features). Галка POST /api/shell/coach, мастер-фича ghost_coach и отдельный тумблер coach_whisper выключают это расширение. В наушники — тот же SessionAlert / SAPI, что у Ghost Coach; отдельного оверлея нет.\n\nНе путать с Party Energy Pool (минуты сессии, не CS$) и Lucky Seat (кейс за стрик).\nКод: PartyEcoDropSynchronizer, GhostCoachService::maybeWhisper. Тесты: GhostCoachPartyTest.",
                        'path' => '/admin/ai-assistant',
                        'audience' => 'Shell / Игрок',
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
                        'description' => "POST /api/shell/power/heartbeat (~30 с) + MAC NIC + lan_ip + cache_ok/free_gb/data_root + maintenance + nic_link_mbps + nic_flap_events + SMART SSD + super_client + Steam/Epic inventory + patch_seed_port → online / очередь WOL / плитки кэша, линка, flap, износа, Super Client, LAN-seed.\nОтвет может содержать diskless, resync, rollback, patch_seed, patch_pull, nic_flap_acked, throne (King дня), features.\nPOST /api/shell/power/offline при штатном уходе.\nВ ответах logout/balance/poll может прийти power_action=reboot|shutdown по desired питания (бронь ± warmup); в техрежиме, Super Client и при активном patch_pull — none.\nMagic packet шлёт MikroTik из /api/power/wol-targets, не шелл и не облако напрямую. Настройка токена и warmup — .env CLUB_*; статусы на дашборде. Подробности — «Питание ПК» в Конфигурации; LAN-патчи и flap — главы ниже.",
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
                        'title' => 'Rollback Markers золотого образа',
                        'description' => "Зачем. После save Super Client нужен снимок «что лежит на D: и в конфигах», чтобы при синем экране или сбое драйвера админ в один клик вернул проверенную ревизию манифестов Steam/Epic и конфигурационных файлов. Это не агент на сервере CCBoot и не откат VHD: для отравленного GPU-драйвера в образе Windows техник берёт restore point CCBoot той же даты, что маркер.\n\nФича. /admin/config/features → «Станции и образ» → Rollback Markers. Выкл — шелл не архивирует, дашборд прячет кнопки, ingest/rollback 422. Настройки: тикет при BSOD, автоподтверждение ревизии, сколько ревизий хранить (проверенная не чистится). Пустой club_features = включено.\n\nПоток\n1) Disable Super Client + save (setup или очередь с дашборда). Шелл пишет pending-флаг и сразу шлёт POST /api/shell/golden-image/revision: SHA256 + тело маленьких файлов (appmanifest_*.acf, Epic *.item, libraryfolders.vdf, config.ini).\n2) Booking кладёт golden_image_revisions. Первая ревизия клуба — verified. Следующие — pending, пока техник не нажмёт «Проверена» на дашборде (или auto_verify).\n3) Штатный shutdown пишет D:/ShellData/cache/power/clean_shutdown.ok. После BSOD флага нет → heartbeat crash_detected + инцидент golden_image_crash, плитка «bsod».\n4) «Откатить образ» / кнопка в ленте инцидентов: POST /admin/api/computers/rollback. Heartbeat отдаёт rollback {command_id, revision_id}. Шелл GET ревизию и записывает тела файлов обратно. Ack ok.\n\nОткат только на verified/superseded, не на свежий pending (он как раз мог сломать драйвер). ПК должен быть онлайн.\nКод: RollbackMarkerWatchdog, GoldenImageRevisionService. Тесты: GoldenImageRevisionTest, ClubFeaturesTest.",
                        'path' => '/admin/config/features',
                        'audience' => 'Техник / Shell / Админ',
                    ],
                    [
                        'title' => 'Hardware Health (свитч/микрик мыши и клавиатуры)',
                        'description' => "Зачем. HID-алерты ловят пропажу/подмену устройства, но не убитый микрик: мышь дабл-кликает сама, клавиша дребезжит или залипает. Технику нужен тикет на конкретный ПК, пока гость ещё за столом.\n\nШелл (HardwareHealthWatchdog) живёт поверх HID-сессии: startWatch логина ставит WH_MOUSE_LL + WH_KEYBOARD_LL, logout снимает. Синтетику (LLKHF_INJECTED / LLMHF_INJECTED) игнорирует — автологин Steam/Riot не триггерит.\n\nМышь: два DOWN одной кнопки (L/R/M) с интервалом ≤ bounce_gap_ms (~40 мс) — это не человеческий дабл-клик (~200–500 мс), а дребезг контакта. Нужно bounce_hits вспышек за window, растянутых на bounce_spread_ms, чтобы баттерфляй/джиттер за пару секунд не открыл тикет.\n\nКлавиатура: дребезг того же скан-кода (DOWN сразу после UP ≤ chatter_gap_ms) с тем же spread; либо залипание не-WASD/не-модификатора: клавиша down ≥ stuck_down_ms и за это время отпустили несколько других клавиш.\n\nТикет: POST /api/shell/incidents type=hardware_switch_fault, description «Проверить свитч/микрик на ПК-ХХ», severity high, payload {kind, reason=bounce|chatter|stuck_key, scan_code/button, hits}. Dedupe пока супервизор не закрыл. Cooldown config.ini [HardwareHealth] ~10 мин.\n\nЛента /admin/incidents, подпись типа «Неисправность свитча/микрика». System-tests «Здоровье станций»: warn при открытом тикете. Тесты: ShellStationWatchdogTest.",
                        'path' => '/admin/incidents',
                        'audience' => 'Техник / Shell / Админ',
                    ],
                    [
                        'title' => 'Rage-Smash (удар по столу / Peripheral Shock)',
                        'description' => "Зачем. Гость в тильте бьёт по столу или молотит клавиатуру — нужен тикет hardware_abuse, закладка на NVR и вежливый оффер напитка, пока админ не мотает 40 каналов.\n\nШелл (RageSmashWatchdog) живёт поверх HID-сессии: startWatch логина ставит WH_MOUSE_LL + WH_KEYBOARD_LL и Raw Input HID Sensor (usage page 0x20 — акселерометр/гироскоп современных игровых мышей). Logout снимает. Синтетику (LLKHF_INJECTED) игнорирует.\n\nIMU: скачок магнитуды HID-репорта относительно EMA-базы ≥ imu_spike (~2.4). Этого достаточно — стол ударили, K/D не обязателен.\nKey-mash: ≥ mash_keys (10) разных VK за mash_window_ms (100 мс). Mouse-shock: сумма |dx|+|dy| ≥ mouse_shock_px за mouse_shock_ms (прокси, если IMU нет). Для mash/shock нужно падение K/D по GSI: ≥ death_need смертей в окне kd_window_ms и смертей больше, чем киллов, либо K/D сессии просел < 70% пика. Сервер дублирует окно по POST /api/shell/gsi (RageSmashService::noteGsi).\n\nТикет: POST /api/shell/incidents type=hardware_abuse, description «Удар по столу на ПК-ХХ», severity high, payload {source=imu|keymash|mouse_shock, keys/g, kills/deaths, kd_*}. Dedupe открытой строки, но каждая вспышка снова ставит метку. Cooldown config.ini [RageSmash] ~3 мин.\n\nNVR: триггер hardware.abuse в /admin/video-surveillance, метка 1 с (pre 1 с) даже если событие не заводили вручную — VideoMarkerService создаёт его при первом fire. Ответ шеллу: calm_down {title, message, drinks[]} — оверлей RageCalmOverlay, заказ через /api/shell/store/checkout, TTS SessionAlert.\n\nЛента /admin/incidents, подпись «Удар по столу / Rage-Smash». Тесты: RageSmashAlertTest, VideoMarkerHikvisionTest (метка без заранее созданного события).",
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
                        'description' => "Hold-to-talk во время сессии: запись с микрофона → POST /api/shell/ai-assistant → SpeechKit STT (или Whisper) → LLM (DeepSeek/OpenAI из админки) → SpeechKit/OpenAI TTS в наушники. В промпт добавляется живой [GSI] (карта, деньги, ульт, бомба), если игрок в матче.\n\nПосле логина: POST /api/shell/voice-greeting. Промпты и ключи — /admin/ai-assistant.\n\nОтдельно: Ghost Coach — пуш-шёпот по GSI без удержания F1 (шаблоны + LLM fallback). Пати CS2: Coach Whisper синхронно шепчет эко-раунд или дроп AWP всей BookingGroup.",
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
