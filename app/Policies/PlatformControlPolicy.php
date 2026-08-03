<?php

namespace App\Policies;

use App\Models\PlatformControl;
use App\Models\User;

class PlatformControlPolicy
{
    public function before(
        User $user,
        string $ability,
    ): bool|null {
        if ($this->isPlatformOwner($user)) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(
        User $user,
        PlatformControl $platformControl,
    ): bool {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(
        User $user,
        PlatformControl $platformControl,
    ): bool {
        return false;
    }

    public function delete(
        User $user,
        PlatformControl $platformControl,
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        PlatformControl $platformControl,
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        PlatformControl $platformControl,
    ): bool {
        return false;
    }

    private function isPlatformOwner(User $user): bool
    {
        if (
            (bool) $user->getAttribute('is_platform_owner') === true ||
            $user->getAttribute('role') === 'platform_owner'
        ) {
            return true;
        }

        if (
            method_exists($user, 'hasRole') &&
            $user->hasRole('platform_owner')
        ) {
            return true;
        }

        return $user
            ->roles()
            ->where('key', 'platform_owner')
            ->exists();
    }
}