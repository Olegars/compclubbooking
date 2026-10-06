<?php

return [

    /*
    | Включает MCP целиком. Пока false, artisan mcp:serve и POST /mcp молчат.
    | HTTP дополнительно требует MCP_HTTP_ENABLED: stdio для Cursor на сервере
    | можно открыть, не публикуя порт.
    */
    'enabled' => (bool) env('MCP_ENABLED', false),

    'http_enabled' => (bool) env('MCP_HTTP_ENABLED', false),

    'confirm_ttl' => max(30, (int) env('MCP_CONFIRM_TTL', 300)),

    'wake_hold_minutes' => max(5, min(30, (int) env('MCP_WAKE_HOLD_MINUTES', 15))),

    'wake_batch_limit' => 40,

    /*
    | Браузерный Origin. Пустой заголовок (Cursor, curl) пропускается.
    | APP_URL разрешён всегда. Остальное — через запятую.
    */
    'allowed_origins' => array_values(array_filter(array_map(
        static fn ($origin) => rtrim(trim((string) $origin), '/'),
        explode(',', (string) env('MCP_ALLOWED_ORIGINS', ''))
    ))),

];
