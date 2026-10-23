<?php

return [
    // Remains OFF until production-like migration rehearsal, real Tawked end-to-end
    // checks, live customer compatibility tests and explicit release approval.
    'enabled' => (bool) env('ZAD_LEGACY_ORDER_RECOVERY_ENABLED', false),
    'allow_live_sms' => (bool) env('ZAD_LEGACY_ORDER_RECOVERY_LIVE_SMS', false),
    'max_daily_sends' => 3,
    'resend_cooldown_seconds' => 120,
];
