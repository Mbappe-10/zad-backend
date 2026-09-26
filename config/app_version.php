<?php

return [
    'android' => [
        'latest_version' => env('ZAD_ANDROID_LATEST_VERSION', '1.0.0'),
        'latest_build' => (int) env('ZAD_ANDROID_LATEST_BUILD', 1),
        'minimum_build' => (int) env('ZAD_ANDROID_MINIMUM_BUILD', 1),
        'force_update' => filter_var(
            env('ZAD_ANDROID_FORCE_UPDATE', false),
            FILTER_VALIDATE_BOOL
        ),
        'store_url' => env(
            'ZAD_ANDROID_STORE_URL',
            'https://play.google.com/store/apps/details?id=com.zadsync.app'
        ),
    ],

    'ios' => [
        'latest_version' => env('ZAD_IOS_LATEST_VERSION', '1.0.0'),
        'latest_build' => (int) env('ZAD_IOS_LATEST_BUILD', 1),
        'minimum_build' => (int) env('ZAD_IOS_MINIMUM_BUILD', 1),
        'force_update' => filter_var(
            env('ZAD_IOS_FORCE_UPDATE', false),
            FILTER_VALIDATE_BOOL
        ),
        'store_url' => env('ZAD_IOS_STORE_URL', ''),
    ],
];