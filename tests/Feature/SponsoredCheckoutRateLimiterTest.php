<?php

namespace Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SponsoredCheckoutRateLimiterTest extends TestCase
{
    public function test_sponsored_checkout_has_guest_and_ip_limits(): void
    {
        $request = Request::create('/api/orders', 'POST', [
            'sponsored_coupon_code' => 'ZAD620',
            'guest_session_id' => 'test-guest-123',
        ], [], [], ['REMOTE_ADDR' => '192.0.2.10']);

        $limits = RateLimiter::limiter('sponsored-checkout')($request);

        $this->assertCount(2, $limits);
        $this->assertSame(5, $limits[0]->maxAttempts);
        $this->assertSame(20, $limits[1]->maxAttempts);
        $this->assertStringStartsWith('sponsored-checkout-guest:', $limits[0]->key);
        $this->assertSame('sponsored-checkout-ip:192.0.2.10', $limits[1]->key);
    }

    public function test_normal_checkout_has_no_extra_limit(): void
    {
        $request = Request::create('/api/orders', 'POST', [
            'guest_session_id' => 'test-guest-123',
        ], [], [], ['REMOTE_ADDR' => '192.0.2.10']);

        $limits = RateLimiter::limiter('sponsored-checkout')($request);

        $this->assertCount(1, $limits);
        $this->assertInstanceOf(Limit::class, $limits[0]);
        $this->assertSame(PHP_INT_MAX, $limits[0]->maxAttempts);
    }
}