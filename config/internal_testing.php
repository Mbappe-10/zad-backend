<?php

$csv = static fn (mixed $value): array => array_values(array_filter(
    array_map(
        static fn (string $item): string => trim($item),
        explode(',', (string) $value),
    ),
    static fn (string $item): bool => $item !== '',
));

return [
    /*
    |--------------------------------------------------------------------------
    | ZADSYNC internal testing
    |--------------------------------------------------------------------------
    |
    | This mode is intentionally opt-in. Keep it disabled for a public release.
    | Private emails and phone numbers belong in Render environment variables,
    | never in source control.
    |
    */
    'enabled' => env('ZAD_INTERNAL_TEST_MODE', false),

    'restrict_role_login' => env(
        'ZAD_INTERNAL_TEST_RESTRICT_ROLE_LOGIN',
        true,
    ),

    'otp' => [
        'expose_code' => env('ZAD_INTERNAL_TEST_EXPOSE_OTP', false),
        'expires_minutes' => (int) env(
            'ZAD_INTERNAL_TEST_OTP_EXPIRES_MINUTES',
            3,
        ),
        'allowed_phones' => $csv(
            env('ZAD_INTERNAL_TEST_PHONE_ALLOWLIST', ''),
        ),
    ],

    'payment' => [
        'simulate_success' => env(
            'ZAD_INTERNAL_TEST_PAYMENT_ENABLED',
            false,
        ),
    ],

    'family' => [
        'email' => env('ZAD_INTERNAL_TEST_FAMILY_EMAIL', ''),
        'store_slug' => env(
            'ZAD_INTERNAL_TEST_FAMILY_STORE_SLUG',
            '',
        ),
        'pickup_address' => env(
            'ZAD_INTERNAL_TEST_STORE_PICKUP_ADDRESS',
            'نقطة استلام تجريبية - مكة المكرمة',
        ),
        'pickup_latitude' => env(
            'ZAD_INTERNAL_TEST_STORE_PICKUP_LATITUDE',
            '',
        ),
        'pickup_longitude' => env(
            'ZAD_INTERNAL_TEST_STORE_PICKUP_LONGITUDE',
            '',
        ),
    ],

    'driver' => [
        'email' => env('ZAD_INTERNAL_TEST_DRIVER_EMAIL', ''),
        'code' => env('ZAD_INTERNAL_TEST_DRIVER_CODE', ''),
        'name' => env('ZAD_INTERNAL_TEST_DRIVER_NAME', ''),
        'phone' => env('ZAD_INTERNAL_TEST_DRIVER_PHONE', ''),
        'city_code' => env(
            'ZAD_INTERNAL_TEST_DRIVER_CITY_CODE',
            'makkah',
        ),
        'vehicle_type' => env(
            'ZAD_INTERNAL_TEST_DRIVER_VEHICLE_TYPE',
            'car',
        ),
        'create_if_missing' => env(
            'ZAD_INTERNAL_TEST_CREATE_DRIVER',
            false,
        ),
    ],
];
