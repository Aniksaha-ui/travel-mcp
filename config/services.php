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

    'travelbooking' => [
        'base_url' => env('TRAVELBOOKING_BASE_URL', 'https://travelbooking.infinitycodehubltd.com/public'),
        'referer' => env('TRAVELBOOKING_REFERER', 'http://swiftjourney.infinitycodehubltd.com/'),
        'origin' => env('TRAVELBOOKING_ORIGIN', 'http://swiftjourney.infinitycodehubltd.com'),
        'verify' => env('TRAVELBOOKING_TLS_VERIFY', false),
        'connect_timeout' => (int) env('TRAVELBOOKING_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('TRAVELBOOKING_TIMEOUT', 20),
    ],

];
