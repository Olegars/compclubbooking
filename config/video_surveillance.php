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

    /*
    | Эпизод инцидента зала: старт за 30 с до события, длина 45 с (15 с после).
    | На FACE-01 один ffmpeg на обе очереди (стол сборки и эпизод). limit выше 1
    | сервис всё равно сжимает до 1: параллельный RTSP в OpenCV и второй ffmpeg
    | дают всплеск RAM и дроп кадров. Стол держит слот дольше нарезки
    | (assembly_clip_stale_minutes, по умолчанию 30 при лимите ролика 20 мин).
    */
    'incident_clip_pre_sec' => (int) env('INCIDENT_CLIP_PRE_SEC', 30),
    'incident_clip_post_sec' => (int) env('INCIDENT_CLIP_POST_SEC', 15),
    'incident_clip_max_kb' => (int) env('INCIDENT_CLIP_MAX_KB', 98304),
    'incident_clip_claim_limit' => (int) env('INCIDENT_CLIP_CLAIM_LIMIT', 1),
    'assembly_clip_claim_limit' => (int) env('ASSEMBLY_CLIP_CLAIM_LIMIT', 1),
    'assembly_clip_stale_minutes' => (int) env('ASSEMBLY_CLIP_STALE_MINUTES', 30),
    'incident_clip_stale_minutes' => (int) env('INCIDENT_CLIP_STALE_MINUTES', 4),
    'incident_clip_max_attempts' => (int) env('INCIDENT_CLIP_MAX_ATTEMPTS', 3),
];
