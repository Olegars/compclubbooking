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
];
