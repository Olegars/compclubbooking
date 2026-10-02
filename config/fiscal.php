<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Фискализация
    |--------------------------------------------------------------------------
    | Облако на кассу не ходит. При FISCAL_ENABLED задание лежит в fiscal_jobs,
    | шлюз в клубе забирает его сам (GET /api/fiscal/targets).
    | Проведение — электронное. Бумага — отдельное задание print_copy.
    | Пока FISCAL_ENABLED=false — заглушка /receipt/stub, очередь пустая.
    */
    'enabled' => (bool) env('FISCAL_ENABLED', false),

    /** Свой токен шлюза. Не переиспользовать кухонный и WOL. */
    'relay_token' => (string) env('FISCAL_RELAY_TOKEN', ''),

    'claim_limit' => (int) env('FISCAL_CLAIM_LIMIT', 5),
    'stale_claim_minutes' => (int) env('FISCAL_STALE_CLAIM_MINUTES', 5),
    'gateway_stale_seconds' => (int) env('FISCAL_GATEWAY_STALE_SECONDS', 90),

    /*
    | Реквизиты чека в нейтральном задании. URL/логин/пароль KkmServer облаку не нужны.
    */
    'kkm' => [
        'inn_kassa' => env('KKM_INN_KASSA', ''),
        'cashier_name' => env('KKM_CASHIER_NAME', ''),
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
        'lucky_seat',
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
