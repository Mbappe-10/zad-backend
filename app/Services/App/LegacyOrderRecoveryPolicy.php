<?php

namespace App\Services\App;

use App\Models\AppGuestSession;
use App\Models\Order;

/** Deliberately limited to closed, paid legacy guest orders. */
final class LegacyOrderRecoveryPolicy
{
    public function eligible(Order $order, AppGuestSession $legacy, AppGuestSession $current, string $phone, string $deviceId): bool
    {
        $originalDevice = trim((string) $legacy->device_id);
        $currentDevice = trim((string) $current->device_id);

        return $legacy->getKey() !== $current->getKey()
            && $legacy->revoked_at === null
            && $current->revoked_at === null
            && $originalDevice !== ''
            && $currentDevice !== ''
            && hash_equals($originalDevice, $currentDevice)
            && hash_equals($currentDevice, trim($deviceId))
            && $order->customer_id === null
            && (string) $order->guest_session_id === (string) $legacy->getKey()
            && $order->isPaid()
            && in_array($order->status, [Order::STATUS_DELIVERED, Order::STATUS_COMPLETED], true)
            && $order->contact_verification_state !== 'pending'
            && preg_match('/^05[0-9]{8}$/D', $phone) === 1
            && hash_equals((string) $order->contact_phone, $phone);
    }
}
