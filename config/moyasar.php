<?php

return [
    'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY', ''),
    'secret_key' => env('MOYASAR_SECRET_KEY', ''),
    'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET', ''),
    'apple_pay_merchant_id' => env('MOYASAR_APPLE_PAY_MERCHANT_ID', ''),
    'apple_pay_label' => env('MOYASAR_APPLE_PAY_LABEL', 'ZAD Sync'),
    'local_test_enabled' => env('MOYASAR_LOCAL_TEST_ENABLED', false),
    'api_url' => env('MOYASAR_API_URL', 'https://api.moyasar.com/v1'),
];