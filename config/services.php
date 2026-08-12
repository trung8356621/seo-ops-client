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

    'google' => [
        'client_id' => env("GOOGLE_CLIENT_ID"),
        'client_secret' => env("GOOGLE_CLIENT_SECRET"),
        'redirect' => env("GOOGLE_REDIRECT_URI")
    ],

    'google_search_console' => [
        'client_id' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET'),
        'redirect' => env(
            'GOOGLE_SEARCH_CONSOLE_REDIRECT_URI',
            rtrim((string) env('APP_URL'), '/').'/seo/oauth/google-search-console/callback'
        ),
    ],

    /*
    | Support ticket remote delivery (optional). Local MySQL is always source of truth
    | for pending tickets; remote may be disabled/offline without blocking submit.
    */
    'support_ticket' => [
        'enabled' => (bool) env('SUPPORT_TICKET_REMOTE_ENABLED', false),
        'endpoint' => env('SUPPORT_TICKET_REMOTE_ENDPOINT'),
        'timeout' => (int) env('SUPPORT_TICKET_REMOTE_TIMEOUT', 5),
    ],

];
