<?php

namespace Tests\Feature;

use App\Models\SponsoredCreatorCoupon;
use App\Models\User;
use App\Services\SponsoredCreatorCouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SponsoredCreatorCouponManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actor(bool $owner): User
    {
        $user = new User();
        $user->exists = true;
        $user->is_platform_owner = $owner;

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (
            DB::getDefaultConnection() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:'
        ) {
            throw new \RuntimeException(
                'SQLite memory database required for sponsored management tests.'
            );
        }
    }

    private function coupon(string $status = 'active'): SponsoredCreatorCoupon
    {
        $now = now();
        $tag = bin2hex(random_bytes(6));

        $owner = User::factory()->create();

        $familyId = DB::table('productive_families')->insertGetId([
            'code' => 'SCM-F-' . $tag,
            'owner_name' => 'Management Test Family',
            'phone' => '05' . random_int(10000000, 99999999),
            'status' => 'approved',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $storeId = DB::table('stores')->insertGetId([
            'productive_family_id' => $familyId,
            'name_ar' => 'متجر اختبار الإدارة',
            'slug' => 'scm-store-' . $tag,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $creatorId = DB::table('launch_creators')->insertGetId([
            'code' => 'SCM-C-' . $tag,
            'name' => 'Management Test Creator',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = DB::table('sponsored_creator_coupons')->insertGetId([
            'creator_id' => $creatorId,
            'store_id' => $storeId,
            'created_by' => $owner->id,
            'code_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'allowed_products' => json_encode([]),
            'gross_cap' => 100,
            'status' => $status,
            'activated_at' => $now->copy()->subMinute(),
            'expires_at' => $now->copy()->addMinutes(10),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return SponsoredCreatorCoupon::findOrFail($id);
    }

    public function test_owner_can_pause_and_resume_coupon(): void
    {
        $service = app(SponsoredCreatorCouponService::class);
        $coupon = $this->coupon();

        $paused = $service->pause($this->actor(true), $coupon->id);

        $this->assertSame('paused', $paused->status);
        $this->assertFalse($paused->isAvailable());

        $resumed = $service->resume($this->actor(true), $coupon->id);

        $this->assertSame('active', $resumed->status);
        $this->assertTrue($resumed->isAvailable());
    }

    public function test_expired_paused_coupon_cannot_resume(): void
    {
        $coupon = $this->coupon('paused');
        $coupon->expires_at = now()->subMinute();
        $coupon->save();

        $this->expectException(HttpException::class);

        app(SponsoredCreatorCouponService::class)->resume(
            $this->actor(true),
            $coupon->id
        );
    }

    public function test_owner_can_extend_active_coupon(): void
    {
        $coupon = $this->coupon();
        $originalExpiry = $coupon->expires_at->copy();

        $updated = app(SponsoredCreatorCouponService::class)->extend(
            $this->actor(true),
            $coupon->id,
            20
        );

        $this->assertSame(
            $originalExpiry->addMinutes(20)->timestamp,
            $updated->expires_at->timestamp
        );
    }

    public function test_expired_coupon_cannot_be_extended(): void
    {
        $coupon = $this->coupon();
        $coupon->expires_at = now()->subMinute();
        $coupon->save();

        $this->expectException(HttpException::class);

        app(SponsoredCreatorCouponService::class)->extend(
            $this->actor(true),
            $coupon->id,
            20
        );
    }

    public function test_consumed_coupon_cannot_be_revoked(): void
    {
        $coupon = $this->coupon('consumed');
        $coupon->consumed_at = now();
        $coupon->save();

        $this->expectException(HttpException::class);

        app(SponsoredCreatorCouponService::class)->revoke(
            $this->actor(true),
            $coupon->id
        );
    }

    public function test_non_owner_cannot_pause_coupon(): void
    {
        $this->expectException(HttpException::class);

        app(SponsoredCreatorCouponService::class)->pause(
            $this->actor(false),
            1
        );
    }

    public function test_non_owner_cannot_resume_coupon(): void
    {
        $this->expectException(HttpException::class);

        app(SponsoredCreatorCouponService::class)->resume(
            $this->actor(false),
            1
        );
    }

    public function test_non_owner_cannot_extend_coupon(): void
    {
        $this->expectException(HttpException::class);

        app(SponsoredCreatorCouponService::class)->extend(
            $this->actor(false),
            1,
            20
        );
    }
}