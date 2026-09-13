<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Режим ИП
    |--------------------------------------------------------------------------
    | УСН «доходы» 6%, есть наёмные → вычет взносов из налога не больше 50%.
    | НДС: спецставки 5%/7% без вычета входного (или 22% с вычетом — vat_mode).
    */
    'entity' => 'ip',
    'regime' => 'usn_income',
    'usn_rate' => 0.06,
    'has_employees' => true,
    'vat_mode' => 'special', // special | standard
    'injury_rate' => 0.002, // 0.2%, 1 класс риска (офис / услуги)
    'income_1pct_threshold' => 300000,

    /*
    | Поступления, которые не деньги клиента (бонусы) — не база УСН/НДС.
    | Совпадает с fiscal.skip_advance_sources.
    */
    'non_cash_sources' => [
        'bonus',
        'promo',
        'achievement',
        'referral',
        'gift',
        'fantiki',
        'admin_bonus',
    ],

    /*
    | Ставки и лимиты по годам. Нет ключа — берём ближайший меньший, иначе последний.
    */
    'years' => [
        2025 => [
            'ip_fixed' => 53658.00,
            'ip_extra_max' => 375606.00,
            'vat_exempt' => 60_000_000,
            'vat_5_until' => 250_000_000,
            'vat_7_until' => 450_000_000,
            'usn_limit' => 450_000_000,
            'employer_base' => 2_759_000,
            'employer_rate' => 0.30,
            'employer_rate_over' => 0.151,
            'standard_vat' => 20.0,
            'ndfl' => [
                ['up_to' => 2_400_000, 'rate' => 0.13],
                ['up_to' => 5_000_000, 'rate' => 0.15],
                ['up_to' => 20_000_000, 'rate' => 0.18],
                ['up_to' => 50_000_000, 'rate' => 0.20],
                ['up_to' => null, 'rate' => 0.22],
            ],
        ],
        2026 => [
            'ip_fixed' => 57390.00,
            'ip_extra_max' => 401730.00, // 8 × фикс − фикс
            'vat_exempt' => 20_000_000,
            'vat_5_until' => 272_500_000,
            'vat_7_until' => 490_500_000,
            'usn_limit' => 490_500_000,
            'employer_base' => 2_979_000,
            'employer_rate' => 0.30,
            'employer_rate_over' => 0.151,
            'standard_vat' => 22.0,
            'ndfl' => [
                ['up_to' => 2_400_000, 'rate' => 0.13],
                ['up_to' => 5_000_000, 'rate' => 0.15],
                ['up_to' => 20_000_000, 'rate' => 0.18],
                ['up_to' => 50_000_000, 'rate' => 0.20],
                ['up_to' => null, 'rate' => 0.22],
            ],
        ],
    ],
];
