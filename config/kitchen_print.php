<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Kitchen ESC/POS auto-print (bar ticket)
    |--------------------------------------------------------------------------
    | Cloud enqueues slips on order create. A LAN agent pulls jobs and sends
    | raw ESC/POS to one Ethernet printer (TCP 9100). USB not supported.
    */
    'enabled' => (bool) env('KITCHEN_PRINT_ENABLED', false),

    'relay_token' => env('KITCHEN_PRINT_RELAY_TOKEN') ?: env('CLUB_WOL_RELAY_TOKEN', ''),

    /** Hint for the club agent (.env on the agent host), not used by cloud. */
    'printer_host' => env('KITCHEN_PRINTER_HOST', '192.168.1.50'),
    'printer_port' => (int) env('KITCHEN_PRINTER_PORT', 9100),

    'claim_limit' => (int) env('KITCHEN_PRINT_CLAIM_LIMIT', 10),
    'stale_claim_minutes' => (int) env('KITCHEN_PRINT_STALE_MINUTES', 2),
];
