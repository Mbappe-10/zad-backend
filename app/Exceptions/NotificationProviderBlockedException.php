<?php

namespace App\Exceptions;

use RuntimeException;

class NotificationProviderBlockedException extends RuntimeException
{
    public function __construct(string $message = 'FCM is disabled or is not fully configured.')
    {
        parent::__construct($message);
    }
}
