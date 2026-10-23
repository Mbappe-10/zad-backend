<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

/** Production/staging can never issue internal OTP, even with a mistaken opt-in. */
final class LegacyInternalOtpPolicy
{
    public static function allowsInternalOtp(string $environment, bool $explicitLocalOptIn): bool
    {
        return $explicitLocalOptIn && in_array(strtolower(trim($environment)), ['local', 'testing'], true);
    }
}
