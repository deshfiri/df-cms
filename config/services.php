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
