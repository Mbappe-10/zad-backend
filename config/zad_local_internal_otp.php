<?php

return [
    // OFF by default; never overrides the environment gate in LegacyInternalOtpPolicy.
    'enabled' => env('ZAD_LOCAL_INTERNAL_OTP_ENABLED', false),
];
