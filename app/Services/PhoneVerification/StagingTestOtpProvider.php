<?php

namespace App\Services\PhoneVerification;

use App\Contracts\OtpDeliveryProvider;

/** Never calls Meta/SMS. Accepts only stage-isolated, allow-listed test sends. */
final class StagingTestOtpProvider implements OtpDeliveryProvider
{
    public function sendAuthenticationCode(
        string $phone,
        string $code,
        string $idempotencyKey,
    ): OtpDeliveryResult {
        if (! StagingTestOtpGuard::allowedPhone($phone)
            || ! hash_equals(StagingTestOtpGuard::codeForRequest($idempotencyKey), $code)) {
            return OtpDeliveryResult::failed('staging_test', 'staging_test_disabled');
        }
        return OtpDeliveryResult::accepted(
            'staging_test',
            'staging-'.substr($idempotencyKey, 0, 24),
        );
    }
}
