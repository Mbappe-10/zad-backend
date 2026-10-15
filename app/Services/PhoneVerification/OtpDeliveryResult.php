<?php

namespace App\Services\PhoneVerification;

final readonly class OtpDeliveryResult
{
    public function __construct(
        public bool $accepted,
        public string $provider,
        public ?string $messageId = null,
        public ?string $failureCode = null,
    ) {
    }

    public static function accepted(
        string $provider,
        ?string $messageId = null,
    ): self {
        return new self(true, $provider, $messageId);
    }

    public static function failed(
        string $provider,
        string $failureCode,
    ): self {
        return new self(false, $provider, null, $failureCode);
    }
}
