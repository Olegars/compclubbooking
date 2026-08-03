<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Fan control defaults
    |--------------------------------------------------------------------------
    |
    | Cloud stores config, room facts and coordination locks.
    | Physical actuator is the Qt shell on club LAN (W5100 HTTP relay).
    | Power is 0–100; for on/off relays any power > 0 means ON.
    |
    */
    'default_on_power' => (int) env('FAN_DEFAULT_ON_POWER', 100),
    'thermal_on_c' => (int) env('FAN_THERMAL_ON_C', 75),
    'thermal_off_c' => (int) env('FAN_THERMAL_OFF_C', 65),
    'http_timeout' => (float) env('FAN_HTTP_TIMEOUT', 2.0),

    /** Seconds between manual on/off/auto toggles shared across all PCs in a room. */
    'manual_cooldown_sec' => (int) env('FAN_MANUAL_COOLDOWN_SEC', 60),

    /** Seconds between competing shell command applies on the same fan. */
    'auto_apply_cooldown_sec' => (int) env('FAN_AUTO_APPLY_COOLDOWN_SEC', 20),

    /** Default W5100 network relay port (HW-584). */
    'w5100_default_port' => (int) env('FAN_W5100_DEFAULT_PORT', 30000),
];
