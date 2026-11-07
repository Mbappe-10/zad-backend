<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\SponsoredCreatorCouponService;
use App\Services\SponsoredCouponRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SponsoredCouponRealRedemptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDefaultConnection() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('SQLite in-memory database required.');
        }
    }

    private function fixture(): array
    {
        $tag = bin2hex(random_bytes(6));
        $owner = User::factory()->create();
        $owner->is_platform_owner = true;
        $owner->save();
        $owner = $owner->fresh();

        $familyId = DB::table('productive_families')->insertGetId([
            'code' => 'SRF-' . $tag,
            'owner_name' => 'Test Family',
            'phone' => '05' . random_int(10000000, 99999999),
            'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $storeId = DB::table('stores')->insertGetId([
            'productive_family_id' => $familyId,
            'name_ar' => 'Sponsored Real Store',
            'slug' => 'sr-store-' . $tag,
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $creatorId = DB::table('launch_creators')->insertGetId([
            'code' => 'SRC-' . $tag,
            'name' => 'Sponsored Real Creator',
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = DB::table('products')->insertGetId([
            'store_id' => $storeId,
            'name_ar' => 'Sponsored Real Product',
            'price' => 20,
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(SponsoredCreatorCouponService::class);
        $created = $service->createDraft($owner, [
            'creator_id' => $creatorId,
            'store_id' => $storeId,
            'allowed_products' => [['product_id' => $productId, 'max_quantity' => 2]],
            'gross_cap' => 60,
        ]);
        $service->activate($owner, $created['coupon']->id, 15);

        $order = Order::query()->create([
            'number' => 'SPON-' . strtoupper($tag),
            'store_id' => $storeId,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
            'subtotal' => 20,
            'delivery_fee' => 5,
            'discount' => 0,
            'tax' => 0,
            'total' => 25,
        ]);
        $order->items()->create([
            'product_id' => $productId,
            'product_name' => 'Sponsored Real Product',
            'quantity' => 1,
            'unit_price' => 20,
            'total' => 20,
        ]);

        return [
            'order' => $order,
            'coupon_id' => $created['coupon']->id,
            'code' => $created['one_time_plain_code'],
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ];
    }

    private function redeem(array $f, string $key = 'real-redemption-1', int $products = 2000, ?array $items = null): void
    {
        app(SponsoredCouponRedemptionService::class)->redeem(
            $f['code'], $f['order'], $items ?? $f['items'], $products, 500, 0, $key
        );
    }

    public function test_real_order_is_funded_and_coupon_consumed(): void
    {
        $f = $this->fixture();
        DB::transaction(fn () => $this->redeem($f));

        $this->assertDatabaseHas('sponsored_order_fundings', [
            'coupon_id' => $f['coupon_id'],
            'order_id' => $f['order']->id,
            'funding_source' => 'zad_platform',
            'products_amount' => 20,
            'delivery_amount' => 5,
            'tax_amount' => 0,
            'funded_total' => 25,
            'status' => 'committed',
        ]);
        $this->assertDatabaseHas('sponsored_creator_coupons', [
            'id' => $f['coupon_id'],
            'status' => 'consumed',
            'consumed_order_id' => $f['order']->id,
        ]);
        $this->assertSame(1, DB::table('sponsored_order_fundings')->count());
    }

    public function test_same_coupon_cannot_fund_second_order(): void
    {
        $f = $this->fixture();
        DB::transaction(fn () => $this->redeem($f));
        try {
            DB::transaction(fn () => $this->redeem($f, 'real-redemption-2'));
            $this->fail('Second redemption unexpectedly succeeded.');
        } catch (ValidationException $e) {
            $this->assertSame(1, DB::table('sponsored_order_fundings')->count());
        }
    }

    public function test_amount_mismatch_rolls_back(): void
    {
        $f = $this->fixture();
        try {
            DB::transaction(fn () => $this->redeem($f, 'bad-amount', 1900));
            $this->fail('Mismatched amount unexpectedly succeeded.');
        } catch (ValidationException $e) {
            $this->assertSame(0, DB::table('sponsored_order_fundings')->count());
            $this->assertDatabaseHas('sponsored_creator_coupons', [
                'id' => $f['coupon_id'], 'status' => 'active',
            ]);
        }
    }

    public function test_items_mismatch_rolls_back(): void
    {
        $f = $this->fixture();
        try {
            DB::transaction(fn () => $this->redeem($f, 'bad-items', 2000, [
                ['product_id' => $f['items'][0]['product_id'], 'quantity' => 2],
            ]));
            $this->fail('Mismatched items unexpectedly succeeded.');
        } catch (ValidationException $e) {
            $this->assertSame(0, DB::table('sponsored_order_fundings')->count());
            $this->assertDatabaseHas('sponsored_creator_coupons', [
                'id' => $f['coupon_id'], 'status' => 'active',
            ]);
        }
    }
}
