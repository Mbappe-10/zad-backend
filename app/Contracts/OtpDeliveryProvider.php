<?php

namespace App\Contracts;

use App\Services\PhoneVerification\OtpDeliveryResult;

interface OtpDeliveryProvider
{
    public function sendAuthenticationCode(
        string $phone,
        string $code,
        string $idempotencyKey,
    ): OtpDeliveryResult;
}
