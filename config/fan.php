<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Fan control defaults
    |--------------------------------------------------------------------------
    |
    | Backend owns relay state. Shell only reports events (session/thermal/manual).
    | Power is 0–100; for on/off relays any power > 0 means ON.
    |
    */
    'default_on_power' => (int) env('FAN_DEFAULT_ON_POWER', 100),
    'thermal_on_c' => (int) env('FAN_THERMAL_ON_C', 75),
    'thermal_off_c' => (int) env('FAN_THERMAL_OFF_C', 65),
    'http_timeout' => (float) env('FAN_HTTP_TIMEOUT', 2.0),
];
