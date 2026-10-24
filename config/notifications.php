<?php

return [
    'queue' => env('NOTIFICATION_QUEUE', 'notifications'),
    'chunk_size' => (int) env('NOTIFICATION_CHUNK_SIZE', 250),

    'fcm' => [
        // Intentionally opt-in. A configured credential alone never enables live sends.
        'enabled' => (bool) env('FCM_ENABLED', false),
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials_json' => env('FCM_SERVICE_ACCOUNT_JSON'),
        'credentials_base64' => env('FCM_SERVICE_ACCOUNT_BASE64'),
        'credentials_path' => env('FCM_SERVICE_ACCOUNT_PATH'),
        'timeout_seconds' => (int) env('FCM_TIMEOUT_SECONDS', 10),
    ],

    'links' => [
        'allowed_schemes' => ['zadsync'],
        'allowed_web_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NOTIFICATION_ALLOWED_WEB_HOSTS', '')),
        ))),
        'allowed_app_paths' => [
            '/notifications',
            '/orders/',
            '/products/',
            '/stores/',
            '/offers/',
        ],
    ],
];
