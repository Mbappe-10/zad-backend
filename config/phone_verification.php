<?php

return [
    'provider' => env('PHONE_VERIFICATION_PROVIDER', 'meta_whatsapp'),

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
