<?php

namespace Tests\Feature;

use App\Models\SponsoredCreatorCoupon;
use App\Models\User;
use App\Services\SponsoredCreatorCouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SponsoredCouponLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'STOP: SQLite in-memory database required.'
            );
        }

        if (
            DB::getDefaultConnection() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:'
        ) {
            throw new \RuntimeException(
                'STOP: Unexpected test database.'
            );
        }
    }

    private function owner(): User
    {
        $user = User::factory()->create();

        $user->is_platform_owner = true;
        $user->save();

        return $user->fresh();
    }

    private function draft(User $owner): SponsoredCreatorCoupon
    {
        $tag = bin2hex(random_bytes(6));

        $familyId = DB::table('productive_families')
            ->insertGetId([
                'code' => 'TEST-FAM-' . $tag,
                'owner_name' => 'Sponsored Test Family',
                'phone' => '05' . random_int(10000000, 99999999),
                'status' => 'approved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $storeId = DB::table('stores')
            ->insertGetId([
                'productive_family_id' => $familyId,
                'name_ar' => 'متجر اختبار المؤثر',
                'slug' => 'test-sponsored-' . $tag,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $creatorId = DB::table('launch_creators')
            ->insertGetId([
                'code' => 'TEST-CREATOR-' . $tag,
                'name' => 'Sponsored Test Creator',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $coupon = new SponsoredCreatorCoupon();
        $coupon->creator_id = $creatorId;
        $coupon->store_id = $storeId;
        $coupon->created_by = $owner->getKey();
        $coupon->code_hash = hash('sha256', random_bytes(32));
        $coupon->allowed_products = [
            ['product_id' => 1, 'max_quantity' => 2],
        ];
        $coupon->gross_cap = '60.00';
        $coupon->status = SponsoredCreatorCoupon::STATUS_DRAFT;
        $coupon->save();

        return $coupon;
    }

    public function test_owner_activates_draft_for_fifteen_minutes(): void
    {
        $owner = $this->owner();
        $coupon = $this->draft($owner);

        $result = app(SponsoredCreatorCouponService::class)
            ->activate($owner, $coupon->id, 15);

        $this->assertSame('active', $result->status);
        $this->assertTrue($result->isAvailable());
        $this->assertNotNull($result->expires_at);
        $this->assertTrue(
            $result->expires_at->between(
                now()->addMinutes(14),
                now()->addMinutes(16)
            )
        );
    }

    public function test_active_coupon_cannot_be_activated_again(): void
    {
        $owner = $this->owner();
        $coupon = $this->draft($owner);
        $service = app(SponsoredCreatorCouponService::class);

        $service->activate($owner, $coupon->id);

        $this->expectException(HttpException::class);

        $service->activate($owner, $coupon->id);
    }

    public function test_revoked_coupon_cannot_be_reactivated(): void
    {
        $owner = $this->owner();
        $coupon = $this->draft($owner);
        $service = app(SponsoredCreatorCouponService::class);

        $service->revoke($owner, $coupon->id);

        $this->assertSame(
            'revoked',
            $coupon->fresh()->status
        );

        $this->expectException(HttpException::class);

        $service->activate($owner, $coupon->id);
    }

    public function test_consumed_coupon_cannot_be_revoked(): void
    {
        $owner = $this->owner();
        $coupon = $this->draft($owner);

        $coupon->status = SponsoredCreatorCoupon::STATUS_CONSUMED;
        $coupon->consumed_at = now();
        $coupon->save();

        $this->expectException(HttpException::class);

        app(SponsoredCreatorCouponService::class)
            ->revoke($owner, $coupon->id);
    }
}