<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Fan control defaults (3-speed cascade on 2 NetMod channels)
    |--------------------------------------------------------------------------
    |
    | Hardware: HW-584 + NetMod (http://host:8080/cmd). K1=channel, K2=channel2.
    | Speed 1: K1=OFF K2=OFF → 120V (night / duty)
    | Speed 2: K1=ON  K2=OFF → 170V (mid / cool-down)
    | Speed 3: K1=OFF K2=ON  → 220V (session max)
    |
    | Jump 1↔3 goes through mid (~2.5s) to ease cascade contactors.
    |
    | There is no true electrical OFF with only 2 CO relays — "off" / force_off
    | maps to speed 1 (night). Orphan alarm = speed >= 2 while all PCs offline.
    |
    */
    'speed_night' => 1,
    'speed_mid' => 2,
    'speed_high' => 3,

    'default_on_power' => (int) env('FAN_DEFAULT_ON_POWER', 3), // high
    'thermal_on_c' => (int) env('FAN_THERMAL_ON_C', 75),
    'thermal_off_c' => (int) env('FAN_THERMAL_OFF_C', 65),
    'http_timeout' => (float) env('FAN_HTTP_TIMEOUT', 2.0),

    'manual_cooldown_sec' => (int) env('FAN_MANUAL_COOLDOWN_SEC', 10),
    'auto_apply_cooldown_sec' => (int) env('FAN_AUTO_APPLY_COOLDOWN_SEC', 20),
    // NetMod TCP listen port for http://{host}:{port}/{cmd}. Factory W5100 path-port stays 30000 on that driver.
    'http_default_port' => (int) env('FAN_HTTP_DEFAULT_PORT', 8080),
    'w5100_default_port' => (int) env('FAN_W5100_DEFAULT_PORT', 30000),
    'max_per_space' => (int) env('FAN_MAX_PER_SPACE', 2),
    // Cascade K1/K2 must be odd+next: 1+2, 3+4, … 15+16 (enforced in bind/admin).

    // MikroTik pull token for shared supply/exhaust fans (falls back to WOL token).
    'shared_relay_token' => (string) (
        env('FAN_SHARED_RELAY_TOKEN')
        ?: env('CLUB_WOL_RELAY_TOKEN', '')
    ),
];
