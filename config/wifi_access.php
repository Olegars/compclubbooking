<?php

return [
    /*
    | Гостевой Wi-Fi: QR → auth-бэкенд (walled garden) → MikroTik пускает MAC.
    | Полного интернета до authorize нет; роутер должен белить этот хост.
    */
    'enabled' => (bool) env('WIFI_ACCESS_ENABLED', false),

    /** Код станции в QR: /wifi/join?station=... */
    'station_code' => (string) env('WIFI_STATION_CODE', 'club'),

    /** Сколько часов действует доступ после authorize */
    'session_hours' => (int) env('WIFI_SESSION_HOURS', 12),

    /**
     * Токен для MikroTik pull (можно = CLUB_WOL_RELAY_TOKEN).
     * GET  /api/wifi/grant-targets?token=
     * POST /api/wifi/grant-applied
     */
    'relay_token' => (string) (
        env('WIFI_RELAY_TOKEN')
        ?: env('CLUB_WOL_RELAY_TOKEN', '')
    ),
];
