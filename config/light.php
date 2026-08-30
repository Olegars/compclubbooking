<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DMX / Art-Net lighting (room-wide, like SpaceFan)
    |--------------------------------------------------------------------------
    |
    | Cloud stores desired color / brightness / effect. PC Shell sends Art-Net
    | UDP on LAN to the node (typical UDP 6454). Booking never talks to the
    | node from the internet.
    |
    | Fixture layouts (start_channel is 1-based DMX):
    |   rgb        — R G B, brightness scales RGB
    |   dimmer_rgb — dimmer R G B (dimmer = brightness)
    |   rgbw       — R G B W (white preset uses W)
    |
    | Rainbow is clock-synced HSV in the shell. Lights follow PC power, not
    | empty sessions: all PCs off → off; any PC on without a session → white;
    | login fades to the player's last color (green on first visit).
    |
    */
    'artnet_port' => (int) env('LIGHT_ARTNET_PORT', 6454),
    'manual_cooldown_sec' => (int) env('LIGHT_MANUAL_COOLDOWN_SEC', 2),
    'auto_apply_cooldown_sec' => (int) env('LIGHT_AUTO_APPLY_COOLDOWN_SEC', 5),
    'default_brightness' => (int) env('LIGHT_DEFAULT_BRIGHTNESS', 80),
    'default_color' => (string) env('LIGHT_DEFAULT_COLOR', 'white'),
    'rainbow_period_ms' => (int) env('LIGHT_RAINBOW_PERIOD_MS', 8000),
    'fade_login_ms' => (int) env('LIGHT_FADE_LOGIN_MS', 2500),
    'fade_idle_ms' => (int) env('LIGHT_FADE_IDLE_MS', 1200),
    'fade_off_ms' => (int) env('LIGHT_FADE_OFF_MS', 800),
    'fade_manual_ms' => (int) env('LIGHT_FADE_MANUAL_MS', 300),
];
