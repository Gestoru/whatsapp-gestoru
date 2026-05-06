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

    'vps' => [
        'api_url'        => env('VPS_API_URL'),
        'api_key'        => env('VPS_API_KEY'),
        'webhook_secret' => env('WEBHOOK_SECRET'),
    ],

    'base44' => [
        'api_url' => env('BASE44_API_URL', 'https://api.base44.com'),
        'app_id'  => env('BASE44_APP_ID'),
        'api_key' => env('BASE44_API_KEY'),
    ],

    'laravel_api_token' => env('LARAVEL_API_TOKEN'),

];
