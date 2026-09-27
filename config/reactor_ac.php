<?php

return [
    'token_connect_ttl' => (int) env('AC_TOKEN_CONNECT_TTL', 120),
    'heartbeat_interval' => (int) env('AC_HEARTBEAT_INTERVAL', 10),
    'heartbeat_stale_sec' => (int) env('AC_HEARTBEAT_STALE_SEC', 25),
    'css_keepalive_interval' => (int) env('AC_CSS_KEEPALIVE_INTERVAL', 30),
    'ban_days_temp' => (int) env('AC_BAN_DAYS_TEMP', 7),
    'evidence_retention_days' => (int) env('AC_EVIDENCE_RETENTION_DAYS', 90),
    'club_subnet' => env('AC_CLUB_SUBNET', '192.168.20.0/24'),
    'disconnect_grace_sec' => (int) env('AC_DISCONNECT_GRACE_SEC', 60),
    'keepalive_http_grace_cycles' => (int) env('AC_KEEPALIVE_HTTP_GRACE_CYCLES', 1),
    'keepalive_http_retry_sec' => (int) env('AC_KEEPALIVE_HTTP_RETRY_SEC', 10),
    'station_seen_sec' => (int) env('AC_STATION_SEEN_SEC', 90),
    'server_secret' => env('AC_SERVER_SECRET', ''),
    'stack' => 'CounterStrikeSharp',
    'min_os' => 'Windows 10 x64',
    'version' => env('AC_CLIENT_VERSION', '0.0.0'),
];
