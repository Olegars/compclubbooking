<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'yookassa' => [
        'shop_id' => env('YOOKASSA_SHOP_ID'),
        'secret_key' => env('YOOKASSA_SECRET_KEY'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'clips_chat_id' => env('TELEGRAM_CLIPS_CHAT_ID'),
        'clips_guest_chat_id' => env('TELEGRAM_CLIPS_GUEST_CHAT_ID'),
        'clips_auto' => env('TELEGRAM_CLIPS_AUTO', false),
    ],

    'faceit' => [
        'api_key' => env('FACEIT_API_KEY'),
        'client_id' => env('FACEIT_CLIENT_ID'),
        'client_secret' => env('FACEIT_CLIENT_SECRET'),
        'redirect' => env('FACEIT_REDIRECT_URI'),
        'webhook_secret' => env('FACEIT_WEBHOOK_SECRET'),
        'hub_id' => env('FACEIT_HUB_ID'),
        'organizer_id' => env('FACEIT_ORGANIZER_ID'),
        'authorize_url' => env('FACEIT_AUTHORIZE_URL', 'https://accounts.faceit.com/oauth/authorize'),
        'token_url' => env('FACEIT_TOKEN_URL', 'https://api.faceit.com/auth/v1/oauth/token'),
        'userinfo_url' => env('FACEIT_USERINFO_URL', 'https://api.faceit.com/auth/v1/userinfo'),
        'data_url' => env('FACEIT_DATA_URL', 'https://open.faceit.com/data/v4'),
    ],

];
