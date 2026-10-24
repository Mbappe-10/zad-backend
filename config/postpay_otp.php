<?php

return [
    // Stage A: explicitly OFF by default. Never enable before the postpay
    // send/check routes, customer resume UI and operational tests are ready.
    'enabled' => (bool) env('ZAD_POSTPAY_OTP_ENABLED', false),
    'provider' => 'tawked_sms',
    'code_length' => 4,
    'verified_days' => 10,
    'resend_cooldown_seconds' => 90, // Match backend/provider until 60 sec confirmed.
    'allow_live_sms' => (bool) env('ZAD_POSTPAY_ALLOW_LIVE_SMS', false),
    // Isolated staging TEST only: accepts Moyasar-confirmed sandbox payment, to one allowed mobile.
    // In addition to these flags, APP_ENV must be exactly staging and tk_test_ must be used.
    'staging_sandbox_sms_enabled' => filter_var(env('ZAD_POSTPAY_STAGING_SANDBOX_SMS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'staging_owner_phone' => trim((string) env('ZAD_POSTPAY_STAGING_OWNER_PHONE', '')),
    'max_daily_sends' => 4,
    'max_phone_edits' => 3,
];
