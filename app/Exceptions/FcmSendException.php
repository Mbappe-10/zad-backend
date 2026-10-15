<?php

namespace App\Exceptions;

use RuntimeException;

class FcmSendException extends RuntimeException
{
    public function __construct(
        public readonly string $providerCode,
        public readonly bool $retryable,
        public readonly bool $invalidToken = false,
    ) {
        parent::__construct('FCM rejected the message: '.$providerCode);
    }
}
