<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Публичные данные клуба
    |--------------------------------------------------------------------------
    | Используются на лендинге и в футере. Пустые значения не отображаются,
    | поэтому незаполненный блок просто исчезает, а не показывает заглушку.
    | Адрес берётся из clubs.address, здесь — только резервное значение.
    */
    'city' => env('CLUB_CITY', ''),
    'address' => env('CLUB_ADDRESS', ''),
    'hours' => env('CLUB_HOURS', 'Круглосуточно, без перерывов'),
    'phone' => env('CLUB_PHONE', ''),
    'map_url' => env('CLUB_MAP_URL', ''),

    'socials' => [
        'telegram' => env('CLUB_TELEGRAM', ''),
        'vk' => env('CLUB_VK', ''),
        'discord' => env('CLUB_DISCORD', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Юридическая информация
    |--------------------------------------------------------------------------
    | Обязательна на сайте, принимающем оплату. Ссылки выводятся в футере
    | только если заполнены.
    */
    'legal' => [
        'entity' => env('CLUB_LEGAL_ENTITY', ''),
        'inn' => env('CLUB_LEGAL_INN', ''),
        'offer_url' => env('CLUB_OFFER_URL', ''),
        'privacy_url' => env('CLUB_PRIVACY_URL', ''),
        'rules_url' => env('CLUB_RULES_URL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Возрастное ограничение
    |--------------------------------------------------------------------------
    */
    'min_age' => (int) env('CLUB_MIN_AGE', 14),

    /*
    |--------------------------------------------------------------------------
    | Тайминг сессии при входе на ПК
    |--------------------------------------------------------------------------
    | early: если ПК свободен — старт в любой момент до starts_at; ends_at
    | сдвигается, чтобы сохранить оплаченную длительность.
    | late: после starts_at + grace без активации бронь снимается (no-show).
    */
    'booking' => [
        'late_start_grace_minutes' => (int) env('CLUB_LATE_START_GRACE_MINUTES', 15),
    ],

    'seo' => [
        'title' => env('CLUB_SEO_TITLE', 'Sector 0451 — киберспортивный клуб'),
        'description' => env(
            'CLUB_SEO_DESCRIPTION',
            'Бронирование игровых мест онлайн: выберите место на карте клуба, время и тариф. Оплата и вход по PIN-коду.'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Бонус за отзыв (Яндекс.Карты / 2ГИС)
    |--------------------------------------------------------------------------
    | Клиент сохраняет текст отзыва; ежедневная команда reactor:check-reviews
    | тянет ленту через fetchReviews и нечётко сверяет текст.
    */
    'reviews' => [
        'bonus_amount' => (float) env('CLUB_REVIEW_BONUS_AMOUNT', 100),
        'match_threshold' => (float) env('CLUB_REVIEW_MATCH_THRESHOLD', 0.9),
        'min_text_length' => (int) env('CLUB_REVIEW_MIN_TEXT_LENGTH', 40),
        'pending_ttl_days' => (int) env('CLUB_REVIEW_PENDING_TTL_DAYS', 30),
        'fetch_pages' => (int) env('CLUB_REVIEW_FETCH_PAGES', 5),
        'page_size' => (int) env('CLUB_REVIEW_PAGE_SIZE', 50),
        'require_five_stars' => filter_var(env('CLUB_REVIEW_REQUIRE_FIVE_STARS', true), FILTER_VALIDATE_BOOLEAN),
        'yandex_org_id' => env('CLUB_YANDEX_ORG_ID', ''),
        'yandex_maps_url' => env('CLUB_YANDEX_MAPS_URL', ''),
        'twogis_firm_id' => env('CLUB_2GIS_FIRM_ID', ''),
        'twogis_url' => env('CLUB_2GIS_URL', ''),
        'twogis_api_key' => env('CLUB_2GIS_API_KEY', ''),
    ],
];
