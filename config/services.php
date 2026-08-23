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

    /*
    |----------------------------------------------------------------------
    | Meta (Facebook) Marketing API
    |----------------------------------------------------------------------
    |
    | App-level credentials only. Per-brand access tokens are stored encrypted
    | on brand_integrations and never appear here or in the frontend.
    |
    */
    'meta' => [
        'app_id'       => env('META_APP_ID'),
        'app_secret'   => env('META_APP_SECRET'),
        'redirect_uri' => env('META_REDIRECT_URI'),
        'api_version'  => env('META_API_VERSION', 'v21.0'),
        // Read-only by default. Ask for ads_management only when the app is
        // actually expected to create or pause campaigns.
        'scopes'       => array_filter(explode(',', (string) env(
            'META_SCOPES',
            'ads_read,business_management,pages_show_list,pages_read_engagement,instagram_basic'
        ))),
    ],

    'google_calendar' => [
        // Path to a Google Cloud service-account JSON key file. Meeting <-> Calendar
        // sync silently no-ops everywhere until this is set and the file exists.
        'credentials_path'  => env('GOOGLE_CALENDAR_CREDENTIALS_PATH'),
        // The calendar to create events on (a shared "bookings" calendar's ID, or 'primary').
        'calendar_id'       => env('GOOGLE_CALENDAR_ID', 'primary'),
        // Optional: Workspace user email to impersonate via domain-wide delegation.
        // Required for Google Meet link generation with a service account — without
        // impersonation, events are still created but Meet links typically won't be.
        'impersonate_email' => env('GOOGLE_CALENDAR_IMPERSONATE_EMAIL'),
    ],

];
