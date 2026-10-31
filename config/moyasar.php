<?php

return [
    'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY', ''),
    // ZAD_MOYASAR_SEPARATE_ENV_KEYS_V1
    'non_live_isolated' => env('MOYASAR_NONLIVE_ISOLATED', false),
    'test_publishable_key' => env('MOYASAR_TEST_PUBLISHABLE_KEY', ''),
    'test_secret_key' => env('MOYASAR_TEST_SECRET_KEY', ''),
    'live_publishable_key' => env('MOYASAR_LIVE_PUBLISHABLE_KEY', ''),
    'live_secret_key' => env('MOYASAR_LIVE_SECRET_KEY', ''),
    'secret_key' => env('MOYASAR_SECRET_KEY', ''),
    'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET', ''),
    'apple_pay_merchant_id' => env('MOYASAR_APPLE_PAY_MERCHANT_ID', ''),
    'apple_pay_label' => env('MOYASAR_APPLE_PAY_LABEL', 'ZAD Sync'),
    'local_test_enabled' => env('MOYASAR_LOCAL_TEST_ENABLED', false),
    'api_url' => env('MOYASAR_API_URL', 'https://api.moyasar.com/v1'),
];