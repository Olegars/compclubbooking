<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Hikvision NVR marker relay (LAN agent)
    |--------------------------------------------------------------------------
    | Cloud cannot reach DS-7764NI-M4 on the club LAN. Jobs are queued and
    | pulled by scripts/hikvision-marker-agent.ps1 (Digest + ISAPI tags).
    */
    'relay_token' => (string) (
        env('VIDEO_MARKER_RELAY_TOKEN')
        ?: env('CLUB_WOL_RELAY_TOKEN', '')
    ),

    'claim_limit' => (int) env('VIDEO_MARKER_CLAIM_LIMIT', 10),
    'stale_claim_minutes' => (int) env('VIDEO_MARKER_STALE_MINUTES', 2),
];
