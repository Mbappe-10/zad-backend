<?php

namespace Tests\Feature;

use App\Models\PlatformRecord;
use App\Services\CouponService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CouponEvaluateIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private CouponService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CouponService::class);
    }

    private function coupon(
        string $code,
        array $overrides = [],
        string $recordStatus = 'active'
    ): PlatformRecord {
        $payload = array_merge([
            'code' => $code,
            'kind' => 'coupon',
            'status' => 'active',
            'discountType' => 'percentage',
            'value' => 10,
            'maxDiscount' => 0,
            'minimumOrder' => 0,
            'usageLimit' => 0,
            'perCustomerLimit' => 0,
            'firstOrderOnly' => false,
            'startDate' => null,
            'endDate' => null,
        ], $overrides);

        return PlatformRecord::query()->create([
            'resource' => 'coupons-offers',
            'external_key' => strtoupper($code),
            'status' => $recordStatus,
            'payload' => $payload,
        ]);
    }

    private function expectCouponRejected(callable $callback): void
    {
        try {
            $callback();

            $this->fail('Expected coupon validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'coupon_code',
                $exception->errors()
            );
        }
    }

    public function test_valid_percentage_coupon_is_accepted(): void
    {
        $this->coupon('SAVE10');

        $result = $this->service->evaluate(
            'save10',
            100,
            15
        );

        $this->assertSame('SAVE10', $result['code']);
        $this->assertSame('percentage', $result['discount_type']);
        $this->assertEquals(10.00, $result['discount_amount']);
        $this->assertEquals(100.00, $result['subtotal']);
        $this->assertEquals(15.00, $result['delivery_fee']);
    }

    public function test_valid_fixed_coupon_is_accepted(): void
    {
        $this->coupon('FIXED15', [
            'discountType' => 'fixed',
            'value' => 15,
        ]);

        $result = $this->service->evaluate(
            'FIXED15',
            100
        );

        $this->assertEquals(15.00, $result['discount_amount']);
    }

    public function test_inactive_platform_record_is_rejected(): void
    {
        $this->coupon(
            'RECORDOFF',
            [],
            'inactive'
        );

        $this->expectCouponRejected(
            fn () => $this->service->evaluate(
                'RECORDOFF',
                100
            )
        );
    }

    public function test_inactive_payload_is_rejected(): void
    {
        $this->coupon('PAYLOADOFF', [
            'status' => 'inactive',
        ]);

        $this->expectCouponRejected(
            fn () => $this->service->evaluate(
                'PAYLOADOFF',
                100
            )
        );
    }

    public function test_non_coupon_promotion_is_rejected(): void
    {
        $this->coupon('NOTCOUPON', [
            'kind' => 'offer',
        ]);

        $this->expectCouponRejected(
            fn () => $this->service->evaluate(
                'NOTCOUPON',
                100
            )
        );
    }

    public function test_future_coupon_is_rejected(): void
    {
        $this->coupon('FUTURE', [
            'startDate' => now()
                ->addDays(2)
                ->toDateString(),
        ]);

        $this->expectCouponRejected(
            fn () => $this->service->evaluate(
                'FUTURE',
                100
            )
        );
    }

    public function test_expired_coupon_is_rejected(): void
    {
        $this->coupon('EXPIRED', [
            'endDate' => now()
                ->subDay()
                ->toDateString(),
        ]);

        $this->expectCouponRejected(
            fn () => $this->service->evaluate(
                'EXPIRED',
                100
            )
        );
    }

    public function test_minimum_order_is_enforced(): void
    {
        $this->coupon('MINIMUM', [
            'minimumOrder' => 100,
        ]);

        $this->expectCouponRejected(
            fn () => $this->service->evaluate(
                'MINIMUM',
                99
            )
        );

        $result = $this->service->evaluate(
            'MINIMUM',
            100
        );

        $this->assertEquals(10.00, $result['discount_amount']);
    }

    public function test_global_usage_limit_is_enforced(): void
    {
        $coupon = $this->coupon('LIMIT1', [
            'usageLimit' => 1,
        ]);

        $familyId = DB::table('productive_families')->insertGetId([
            'code' => 'PF-LIMIT1',
            'owner_name' => 'Coupon Test Family',
            'phone' => '0500000001',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $storeId = DB::table('stores')->insertGetId([
            'productive_family_id' => $familyId,
            'name_ar' => 'Coupon Test Store',
            'slug' => 'coupon-test-store-limit1',
            'status' => 'active',
            'is_open' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Coupon Test Customer',
            'phone' => '0500000002',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'number' => 'TEST-LIMIT1-ORDER',
            'customer_id' => $customerId,
            'store_id' => $storeId,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 100,
            'delivery_fee' => 0,
            'discount' => 10,
            'tax' => 0,
            'total' => 90,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_coupon_usages')->insert([
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'platform_record_id' => $coupon->id,
            'coupon_code' => 'LIMIT1',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'max_discount' => 0,
            'minimum_order' => 0,
            'subtotal_snapshot' => 100,
            'delivery_fee_snapshot' => 0,
            'discount_amount' => 10,
            'status' => 'consumed',
            'coupon_snapshot' => json_encode([
                'code' => 'LIMIT1',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectCouponRejected(
            fn () => $this->service->evaluate(
                'LIMIT1',
                100
            )
        );
    }

    public function test_unknown_coupon_is_rejected(): void
    {
        $this->expectCouponRejected(
            fn () => $this->service->evaluate(
                'DOES-NOT-EXIST',
                100
            )
        );
    }

    public function test_snapshot_contains_coupon_financial_rules(): void
    {
        $this->coupon('SNAP20', [
            'discountType' => 'percentage',
            'value' => 20,
            'maxDiscount' => 25,
            'minimumOrder' => 50,
            'usageLimit' => 100,
            'perCustomerLimit' => 2,
        ]);

        $result = $this->service->evaluate(
            'SNAP20',
            100,
            12
        );

        $this->assertSame(
            'SNAP20',
            $result['snapshot']['code']
        );

        $this->assertSame(
            'percentage',
            $result['snapshot']['discountType']
        );

        $this->assertEquals(
            20.00,
            $result['discount_amount']
        );

        $this->assertEquals(
            20.00,
            $result['snapshot']['value']
        );

        $this->assertEquals(
            25.00,
            $result['snapshot']['maxDiscount']
        );

        $this->assertEquals(
            50.00,
            $result['snapshot']['minimumOrder']
        );
    }

    public function test_stale_creator_managed_coupon_is_rejected_even_if_reactivated(): void
    {
        $this->coupon('STALECREATOR', [
            'status' => 'active',
            'creatorContractManaged' => true,
            'creatorAssignmentId' => 999999,
            'creatorContractVersion' => 2,
        ]);

        $this->expectCouponRejected(
            fn () => $this->service->evaluate(
                'STALECREATOR',
                100
            )
        );
    }}