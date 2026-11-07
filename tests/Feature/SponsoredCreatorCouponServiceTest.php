<?php

namespace Tests\Feature;

use App\Models\SponsoredCreatorCoupon;
use App\Models\User;
use App\Services\SponsoredCreatorCouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SponsoredCreatorCouponServiceTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = new User();
        $user->exists = true;
        $user->is_platform_owner = true;
        return $user;
    }

    private function nonOwner(): User
    {
        $user = new User();
        $user->exists = true;
        $user->is_platform_owner = false;
        return $user;
    }

    public function test_non_owner_cannot_create_coupon(): void
    {
        $this->expectException(HttpException::class);

        app(SponsoredCreatorCouponService::class)->createDraft(
            $this->nonOwner(),
            []
        );
    }

    public function test_non_owner_cannot_activate_coupon(): void
    {
        $this->expectException(HttpException::class);

        app(SponsoredCreatorCouponService::class)->activate(
            $this->nonOwner(),
            1
        );
    }

    public function test_non_owner_cannot_revoke_coupon(): void
    {
        $this->expectException(HttpException::class);

        app(SponsoredCreatorCouponService::class)->revoke(
            $this->nonOwner(),
            1
        );
    }

    public function test_draft_coupon_is_not_available(): void
    {
        $coupon = new SponsoredCreatorCoupon();
        $coupon->status = SponsoredCreatorCoupon::STATUS_DRAFT;

        $this->assertFalse($coupon->isAvailable());
    }

    public function test_active_coupon_with_valid_expiry_is_available(): void
    {
        $coupon = new SponsoredCreatorCoupon();
        $coupon->status = SponsoredCreatorCoupon::STATUS_ACTIVE;
        $coupon->activated_at = now()->subMinute();
        $coupon->expires_at = now()->addMinutes(10);

        $this->assertTrue($coupon->isAvailable());
    }

    public function test_expired_coupon_is_not_available(): void
    {
        $coupon = new SponsoredCreatorCoupon();
        $coupon->status = SponsoredCreatorCoupon::STATUS_ACTIVE;
        $coupon->activated_at = now()->subMinutes(20);
        $coupon->expires_at = now()->subMinute();

        $this->assertFalse($coupon->isAvailable());
    }

    public function test_consumed_coupon_is_not_available(): void
    {
        $coupon = new SponsoredCreatorCoupon();
        $coupon->status = SponsoredCreatorCoupon::STATUS_CONSUMED;
        $coupon->activated_at = now()->subMinute();
        $coupon->expires_at = now()->addMinutes(10);
        $coupon->consumed_at = now();

        $this->assertFalse($coupon->isAvailable());
    }
}