<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Фискализация (KkmServer)
    |--------------------------------------------------------------------------
    | Пополнение кошелька → чек «АВАНС».
    | Списание на бронь / магазин → чек «ПОЛНЫЙ РАСЧЁТ» с зачётом аванса.
    | Пока FISCAL_ENABLED=false — только учёт в transactions, без вызова ККТ.
    */
    'enabled' => (bool) env('FISCAL_ENABLED', false),

    'kkm' => [
        'url' => env('KKM_SERVER_URL', 'http://127.0.0.1:5893/Execute'),
        'user' => env('KKM_SERVER_USER', 'Admin'),
        'password' => env('KKM_SERVER_PASS', ''),
        'num_device' => (int) env('KKM_NUM_DEVICE', 0),
        'inn_kassa' => env('KKM_INN_KASSA', ''),
        'cashier_name' => env('KKM_CASHIER_NAME', 'REACTOR System'),
        'timeout' => (int) env('KKM_TIMEOUT', 15),
        /** Не печатать бумагу — только ОФД / электронный чек */
        'not_print' => (bool) env('KKM_NOT_PRINT', false),
        /** Ставка НДС: -1 без НДС (УСН/патент) */
        'tax' => (int) env('KKM_TAX', -1),
    ],

    /*
    | Источники deposit, по которым НЕ бьём аванс (бонусы / промо).
    | Все остальные положительные deposit (карта, СБП, ЮMoney, cash…) → аванс.
    */
    'skip_advance_sources' => [
        'bonus',
        'promo',
        'achievement',
        'referral',
        'gift',
        'fantiki',
        'admin_bonus',
    ],

    /*
    | @deprecated — оставлен для совместимости; приоритет у skip_advance_sources.
    */
    'advance_sources' => [
        'card',
        'sbp',
        'cash',
        'admin_cash',
        'yookassa',
    ],

    /*
    | Типы списания → зачёт аванса (полный расчёт).
    */
    'settlement_types' => [
        'booking',
        'booking_upgrade',
        'purchase',
    ],

    /*
    | Эти settlement-типы НЕ бьём в момент списания с кошелька.
    | Чек «полный расчёт» — при старте сессии (shell login) / no-show без возврата.
    | purchase остаётся мгновенным (передача товара).
    */
    'deferred_settlement_types' => [
        'booking',
        'booking_upgrade',
    ],

];
