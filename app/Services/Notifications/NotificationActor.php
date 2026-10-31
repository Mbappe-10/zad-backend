<?php

namespace App\Services\Notifications;

use App\Models\AppGuestSession;
use App\Models\User;

final readonly class NotificationActor
{
    public function __construct(
        public ?User $user = null,
        public ?AppGuestSession $guest = null,
    ) {
    }

    public function key(): string
    {
        return $this->user
            ? 'user:'.$this->user->getKey()
            : 'guest:'.$this->guest?->getKey();
    }
}
