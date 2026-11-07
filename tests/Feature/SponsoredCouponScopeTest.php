<?php

namespace Tests\Feature;

use App\Models\PlatformRecord;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SponsoredCouponScopeTest extends TestCase
{
    use RefreshDatabase;

    private function coupon(string $code, array $overrides = []): void
    {
        PlatformRecord::query()->create([
            'resource' => 'coupons-offers',
            'external_key' => $code,
            'status' => 'active',
            'payload' => array_replace([
                'code' => $code,
                'kind' => 'coupon',
                'status' => 'active',
                'discountScope' => 'all',
                'sponsoredByPlatform' => true,
                'discountType' => 'percentage',
                'value' => 100,
                'maxDiscount' => 0,
                'usageLimit' => 1,
                'perCustomerLimit' => 1,
                'zadSharePercent' => 100,
                'familySharePercent' => 0,
                'driverSharePercent' => 0,
            ], $overrides),
        ]);
    }

    public function test_sponsored_coupon_covers_products_delivery_and_tax(): void
    {
        $this->coupon('ZADFREE1');
        $result = app(CouponService::class)->evaluate('ZADFREE1', 35, 10, null, null, 5);
        $this->assertEquals(50.0, $result['discount_amount']);
        $this->assertSame('all', $result['discount_scope']);
        $this->assertTrue($result['sponsored_by_platform']);
        $this->assertEquals(50.0, $result['zad_funded_amount']);
        $this->assertEquals(0.0, $result['family_funded_amount']);
        $this->assertEquals(0.0, $result['driver_funded_amount']);
    }

    public function test_all_scope_without_sponsorship_is_rejected(): void
    {
        $this->coupon('NOTSPONSORED', ['sponsoredByPlatform' => false]);
        $this->expectException(ValidationException::class);
        app(CouponService::class)->evaluate('NOTSPONSORED', 35, 10, null, null, 5);
    }

    public function test_sponsored_coupon_with_family_share_is_rejected(): void
    {
        $this->coupon('BADSHARE', ['zadSharePercent' => 90, 'familySharePercent' => 10]);
        $this->expectException(ValidationException::class);
        app(CouponService::class)->evaluate('BADSHARE', 35, 10, null, null, 5);
    }

    public function test_sponsored_coupon_with_unlimited_uses_is_rejected(): void
    {
        $this->coupon('BADLIMIT', ['usageLimit' => 0]);
        $this->expectException(ValidationException::class);
        app(CouponService::class)->evaluate('BADLIMIT', 35, 10, null, null, 5);
    }
}
