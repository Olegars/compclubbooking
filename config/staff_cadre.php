<?php

return [

    /*
    | Реквизиты страхователя. ИП — ИНН 12 цифр, КПП пустой.
    | Регномер СФР и код инспекции нужны для выгрузки.
    */
    'employer' => [
        'name' => env('CLUB_LEGAL_ENTITY', ''),
        'inn' => env('CLUB_LEGAL_INN', ''),
        'kpp' => env('CLUB_LEGAL_KPP', ''),
        'sfr_reg_number' => env('CLUB_SFR_REG', ''),
        'tax_office' => env('CLUB_TAX_OFFICE', ''),
    ],

    'okz' => [
        'admin' => ['code' => '4222.0', 'title' => 'Администратор зала'],
        'intern' => ['code' => '4222.0', 'title' => 'Администратор зала'],
        'supervisor' => ['code' => '3343.3', 'title' => 'Старший администратор'],
        'store_manager' => ['code' => '5230.1', 'title' => 'Кассир'],
        'senior_manager' => ['code' => '5230.1', 'title' => 'Кассир'],
        'assembler' => ['code' => '7422.2', 'title' => 'Сборщик ПК'],
        'owner' => ['code' => '3343.3', 'title' => 'Старший администратор'],
    ],

    'fire_reasons' => [
        'article_77_3' => [
            'label' => 'п. 3 ч. 1 ст. 77 ТК РФ — инициатива работника',
            'sfr' => 'п3ч1с77',
        ],
        'article_81_6a' => [
            'label' => 'пп. «а» п. 6 ч. 1 ст. 81 ТК РФ — прогул',
            'sfr' => 'ппап6ч1с81',
        ],
    ],

    'part_time' => [
        'НЕПД' => 'Неполный рабочий день',
        'НЕПН' => 'Неполная рабочая неделя',
    ],

    'xsd' => [
        'efs1' => resource_path('xsd/efs1-subsection-1.1.xsd'),
        'pers' => resource_path('xsd/pers-records-1151162.xsd'),
    ],
];
