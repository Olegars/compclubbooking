<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Токен для USB-утилиты сверки сборки (check_build.exe)
    | Заголовок: Authorization: Bearer {token}  или  X-Build-Verify-Token
    |--------------------------------------------------------------------------
    */
    'build_verify_token' => env('STORE_BUILD_VERIFY_TOKEN', ''),

    /*
    | Разрешить обновлять имена комплектующих по данным с ПК
    */
    'build_verify_update_names' => env('STORE_BUILD_VERIFY_UPDATE_NAMES', true),

    /*
    | Срок гарантии на готовую сборку (месяцев)
    */
    'warranty_months' => (int) env('STORE_WARRANTY_MONTHS', 12),

    /*
    | Срок гарантийного ремонта (дней)
    */
    'repair_days' => (int) env('STORE_REPAIR_DAYS', 45),

    /*
    | Канал NVR над столом сборщика (1 = track 101). Пусто — default_channel видеонаблюдения.
    */
    'assembly_nvr_channel' => env('STORE_ASSEMBLY_NVR_CHANNEL', ''),

    /*
    | Максимум минут ролика сборки, который агент выгружает с NVR на паспорт.
    */
    'assembly_clip_max_minutes' => (int) env('STORE_ASSEMBLY_CLIP_MAX_MINUTES', 20),

    /*
    |--------------------------------------------------------------------------
    | API поставщика (QuickFox)
    |--------------------------------------------------------------------------
    | domain — хост без /api/2 (например https://b2b.example.ru)
    | category_ids — опционально, через запятую: синк только этих веток + дочерних
    */
    'quickfox' => [
        'domain' => env('STORE_QUICKFOX_DOMAIN', ''),
        'login' => env('STORE_QUICKFOX_LOGIN', ''),
        'password' => env('STORE_QUICKFOX_PASSWORD', ''),
        'catalog_tree_path' => env('STORE_QUICKFOX_CATALOG_TREE', '/download/catalog/json/catalog_tree_9.json'),
        'products_path' => env('STORE_QUICKFOX_PRODUCTS', '/download/catalog/json/products_9.json'),
        'category_ids' => array_values(array_filter(array_map(
            'intval',
            array_map('trim', explode(',', (string) env('STORE_QUICKFOX_CATEGORY_IDS', '')))
        ))),
        /*
        | Ключевые слова названий категорий ITP для фильтра поиска по типу сметы.
        | Можно переопределить позже через конфиг/env при необходимости.
        */
        'type_category_keywords' => null,
    ],

    /*
    | Avito OAuth (client_credentials). Токен ~24 часа, обновляет store:refresh-avito-token.
    | Мессенджер магазина использует аккаунт Компстор (STORE_AVITO_*).
    */
    'avito' => [
        'client_id' => env('STORE_AVITO_CLIENT_ID', ''),
        'client_secret' => env('STORE_AVITO_CLIENT_SECRET', ''),
        'user_id' => (int) env('STORE_AVITO_USER_ID', 0),
        'shops' => [
            'compmaster' => [
                'client_id' => env('STORE_AVITO_COMPMASTER_CLIENT_ID', ''),
                'client_secret' => env('STORE_AVITO_COMPMASTER_CLIENT_SECRET', ''),
                'user_id' => (int) env('STORE_AVITO_COMPMASTER_USER_ID', 0),
            ],
            'dicomp' => [
                'client_id' => env('STORE_AVITO_DICOMP_CLIENT_ID', ''),
                'client_secret' => env('STORE_AVITO_DICOMP_CLIENT_SECRET', ''),
                'user_id' => (int) env('STORE_AVITO_DICOMP_USER_ID', 0),
            ],
        ],
    ],
];
