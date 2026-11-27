<?php

namespace App\Services\App;

use App\Models\CashierDevice;
use App\Models\CashierStaff;
use App\Models\Store;

/**
 * Who is using the cashier right now: the family owner from her own account,
 * an employee from her own Zad account, or an employee who unlocked a linked
 * cashier device with her PIN.
 */
final class CashierContext
{
    public function __construct(
        public readonly Store $store,
        public readonly ?CashierStaff $staff,
        public readonly ?CashierDevice $device = null,
    ) {}

    public function isOwner(): bool
    {
        return $this->staff === null || $this->staff->isOwner();
    }

    public function can(string $permission): bool
    {
        return $this->isOwner() || in_array($permission, (array) ($this->staff?->permissions ?? []), true);
    }
}
