<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\SponsoredCreatorCoupon;
use App\Services\SponsoredCreatorCouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SponsoredCouponCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            DB::getDefaultConnection() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:'
        ) {
            throw new \RuntimeException('SQLite memory database required.');
        }
    }

    private function fixtures(): array
    {
        $owner = User::factory()->create();
        $owner->is_platform_owner = true;
        $owner->save();
        $owner = $owner->fresh();

        $tag = bin2hex(random_bytes(6));

        $familyId = DB::table('productive_families')->insertGetId([
            'code' => 'SCF-' . $tag,
            'owner_name' => 'Test Family',
            'phone' => '05' . random_int(10000000, 99999999),
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $storeId = DB::table('stores')->insertGetId([
            'productive_family_id' => $familyId,
            'name_ar' => 'متجر تجريبي',
            'slug' => 'sc-store-' . $tag,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherStoreId = DB::table('stores')->insertGetId([
            'productive_family_id' => $familyId,
            'name_ar' => 'متجر آخر',
            'slug' => 'sc-other-' . $tag,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $creatorId = DB::table('launch_creators')->insertGetId([
            'code' => 'SCC-' . $tag,
            'name' => 'Test Creator',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('products')->insertGetId([
            'store_id' => $storeId,
            'name_ar' => 'منتج اختبار',
            'price' => 20,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact(
            'owner', 'creatorId', 'storeId',
            'otherStoreId', 'productId'
        );
    }

    private function payload(array $f, ?int $storeId = null): array
    {
        return [
            'creator_id' => $f['creatorId'],
            'store_id' => $storeId ?? $f['storeId'],
            'allowed_products' => [
                [
                    'product_id' => $f['productId'],
                    'max_quantity' => 2,
                ],
            ],
            'gross_cap' => 60,
        ];
    }

    public function test_owner_creates_draft_coupon(): void
    {
        $f = $this->fixtures();

        $result = app(SponsoredCreatorCouponService::class)
            ->createDraft($f['owner'], $this->payload($f));

        $coupon = $result['coupon'];

        $this->assertSame(
            SponsoredCreatorCoupon::STATUS_DRAFT,
            $coupon->status
        );

        $this->assertFalse($coupon->isAvailable());

        $this->assertSame(
            hash('sha256', $result['one_time_plain_code']),
            $coupon->code_hash
        );

        $this->assertDatabaseHas('sponsored_creator_coupons', [
            'id' => $coupon->id,
            'creator_id' => $f['creatorId'],
            'store_id' => $f['storeId'],
        ]);
    }

    public function test_product_from_other_store_is_rejected(): void
    {
        $f = $this->fixtures();

        $this->expectException(ValidationException::class);

        app(SponsoredCreatorCouponService::class)->createDraft(
            $f['owner'],
            $this->payload($f, $f['otherStoreId'])
        );
    }

    public function test_created_coupon_is_not_active(): void
    {
        $f = $this->fixtures();

        $result = app(SponsoredCreatorCouponService::class)
            ->createDraft($f['owner'], $this->payload($f));

        $this->assertNull($result['coupon']->activated_at);
        $this->assertNull($result['coupon']->expires_at);
        $this->assertSame('draft', $result['coupon']->status);
    }
}