<?php

return [
    'provider' => env('PHONE_VERIFICATION_PROVIDER', 'meta_whatsapp'),
    'staging_test_enabled' => env('ZAD_STAGING_TEST_OTP_ENABLED', false),
    'staging_test_secret' => env('ZAD_STAGING_TEST_OTP_SECRET', ''),
    'staging_test_phones' => env('ZAD_STAGING_TEST_OTP_PHONES', ''),
    'qa_auto_verify' => env('ZAD_STAGING_QA_AUTO_VERIFY', false),

    // Keep six digits until the approved Meta authentication template is
    // explicitly verified to support a different length.
    'code_length' => 6,
    'challenge_ttl_seconds' => 300,
    'resend_cooldown_seconds' => 90,
    'max_attempts' => 3,
    'lockout_seconds' => 900,
    'max_daily_sends' => 4,
    'verified_days' => 10,
    'checkout_token_minutes' => 20,
    'guest_session_days' => 10,
];
