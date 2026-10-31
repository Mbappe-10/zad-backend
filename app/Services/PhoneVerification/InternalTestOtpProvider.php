<?php

namespace App\Services\PhoneVerification;

use App\Contracts\OtpDeliveryProvider;
use App\Support\InternalTesting;

class InternalTestOtpProvider implements OtpDeliveryProvider
{
    public function sendAuthenticationCode(
        string $phone,
        string $code,
        string $idempotencyKey,
    ): OtpDeliveryResult {
        if (
            ! app()->environment(['local', 'testing'])
            || ! InternalTesting::enabled()
            || ! InternalTesting::phoneIsAllowed($phone)
        ) {
            return OtpDeliveryResult::failed(
                'internal_test',
                'internal_test_phone_not_allowed',
            );
        }

        // This provider deliberately does not log, return, or persist the code.
        // Automated tests replace it with an in-memory fake to inspect delivery.
        return OtpDeliveryResult::accepted(
            'internal_test',
            'internal-'.substr($idempotencyKey, 0, 24),
        );
    }
}
