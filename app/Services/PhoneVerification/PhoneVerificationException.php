<?php

namespace App\Services\PhoneVerification;

use RuntimeException;

class PhoneVerificationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function responsePayload(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            ...$this->details,
        ];
    }
}
