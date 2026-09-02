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

    // WhatsApp Cloud API fallback credentials. The provider prefers values
    // stored in Settings (whatsapp.*); these env values are only used when a
    // setting is empty, so ops can supply secrets via env instead of the DB.
    'whatsapp' => [
        'meta_phone_number_id' => env('WHATSAPP_META_PHONE_NUMBER_ID'),
        'meta_access_token' => env('WHATSAPP_META_ACCESS_TOKEN'),
        'meta_api_version' => env('WHATSAPP_META_API_VERSION', 'v21.0'),
        'meta_template_language' => env('WHATSAPP_META_TEMPLATE_LANGUAGE', 'ar'),
        // Webhook: the verify token echoed on the GET handshake and the app
        // secret used to validate the X-Hub-Signature-256 of POST payloads.
        'meta_verify_token' => env('WHATSAPP_META_VERIFY_TOKEN'),
        'meta_app_secret' => env('WHATSAPP_META_APP_SECRET'),
    ],

];
