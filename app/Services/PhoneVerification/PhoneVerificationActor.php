<?php

namespace App\Services\PhoneVerification;

use App\Models\AppGuestSession;
use App\Models\User;

final readonly class PhoneVerificationActor
{
    public function __construct(
        public ?User $user,
        public ?AppGuestSession $guest,
        public ?string $deviceHash,
        public string $ipHash,
    ) {
    }

    public function userId(): ?int
    {
        return $this->user?->getKey();
    }

    public function guestId(): ?string
    {
        return $this->guest?->getKey();
    }

    public function key(): string
    {
        return implode('|', [
            $this->userId() !== null ? 'user:'.$this->userId() : 'user:none',
            $this->guestId() !== null ? 'guest:'.$this->guestId() : 'guest:none',
            'device:'.($this->deviceHash ?? 'none'),
            'ip:'.$this->ipHash,
        ]);
    }
}
