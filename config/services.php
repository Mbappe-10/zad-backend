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
    |--------------------------------------------------------------------------
    | Google Authentication
    |--------------------------------------------------------------------------
    |
    | Google OAuth Web Client ID used to verify ID tokens received
    | from the ZADSYNC Flutter application.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'livekit' => [
    'url' => env('LIVEKIT_URL'),
    'api_key' => env('LIVEKIT_API_KEY'),
    'api_secret' => env('LIVEKIT_API_SECRET'),
   ],

    'meta_whatsapp' => [
        'base_url' => env(
            'META_WHATSAPP_BASE_URL',
            'https://graph.facebook.com',
        ),
        'graph_version' => env('META_WHATSAPP_GRAPH_VERSION', ''),
        'business_account_id' => env('META_WHATSAPP_BUSINESS_ACCOUNT_ID', ''),
        'phone_number_id' => env('META_WHATSAPP_PHONE_NUMBER_ID', ''),
        'access_token' => env('META_WHATSAPP_ACCESS_TOKEN', ''),
        'authentication_template' => env(
            'META_WHATSAPP_AUTH_TEMPLATE',
            '',
        ),
        'template_language' => env(
            'META_WHATSAPP_AUTH_TEMPLATE_LANGUAGE',
            'ar',
        ),
        'authentication_template_approved' => env(
            'META_WHATSAPP_AUTH_TEMPLATE_APPROVED',
            false,
        ),
        'authentication_template_code_length' => (int) env(
            'META_WHATSAPP_AUTH_TEMPLATE_CODE_LENGTH',
            6,
        ),
        'timeout_seconds' => (int) env(
            'META_WHATSAPP_TIMEOUT_SECONDS',
            10,
        ),
    ],

];
