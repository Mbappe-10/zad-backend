<?php

namespace Tests\Unit;

use App\Models\Order;
use PHPUnit\Framework\TestCase;

final class PostpayContactGateTest extends TestCase
{
    public function test_legacy_paid_order_is_not_blocked(): void
    {
        $order = new Order(['payment_status' => Order::PAYMENT_PAID]);
        self::assertTrue($order->isPaidAndContactVerified());
    }

    public function test_paid_unverified_order_is_held(): void
    {
        $order = new Order([
            'payment_status' => Order::PAYMENT_PAID,
            'contact_verification_state' => 'pending',
        ]);
        self::assertFalse($order->isPaidAndContactVerified());
        self::assertFalse($order->isAssignable());
    }

    public function test_order_is_released_only_after_verification(): void
    {
        $order = new Order([
            'payment_status' => Order::PAYMENT_PAID,
            'contact_verification_state' => 'verified',
            'status' => Order::STATUS_READY,
        ]);
        self::assertTrue($order->isPaidAndContactVerified());
        self::assertTrue($order->isAssignable());
    }

    public function test_verified_but_unpaid_order_is_never_released(): void
    {
        $order = new Order([
            'payment_status' => Order::PAYMENT_PENDING,
            'contact_verification_state' => 'verified',
            'status' => Order::STATUS_READY,
        ]);
        self::assertFalse($order->isPaidAndContactVerified());
        self::assertFalse($order->isAssignable());
    }
}
