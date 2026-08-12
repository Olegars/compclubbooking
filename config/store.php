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
    ],
];
