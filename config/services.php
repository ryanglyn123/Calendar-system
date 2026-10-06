<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),

        // Google Calendar integration (same OAuth client as sign-in). The
        // calendar connection has its own callback because sign-in routes
        // are guest-only.
        'calendar_redirect' => env('GOOGLE_CALENDAR_REDIRECT_URI', '/settings/google-calendar/callback'),
        // Push notifications need a public HTTPS address; without one the
        // app falls back to polling with sync tokens.
        'calendar_webhook' => env('GOOGLE_CALENDAR_WEBHOOK_URL', rtrim((string) env('APP_URL'), '/').'/webhooks/google-calendar'),
        // Server-side key for reading public holiday calendars (no user
        // account involved). Restrict it to the Calendar API.
        'calendar_api_key' => env('GOOGLE_CALENDAR_API_KEY'),
    ],

];
