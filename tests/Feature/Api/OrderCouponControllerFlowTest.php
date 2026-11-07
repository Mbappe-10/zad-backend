<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\App\OrderCouponController;
use App\Models\Order;
use App\Models\OrderCouponUsage;
use App\Models\PlatformRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrderCouponControllerFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(string $session = 'test-guest-123'): Order
    {
        static $familySequence = 0;
        $familySequence++;
        $now = now();
        $family = DB::table('productive_families')->insertGetId([
            'code' => 'PF-'.uniqid(), 'owner_name' => 'Test Family',
            'phone' => sprintf('050%07d', $familySequence), 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $store = DB::table('stores')->insertGetId([
            'productive_family_id' => $family, 'name_ar' => 'Test Store',
            'slug' => 'coupon-flow-'.uniqid(), 'status' => 'active', 'is_open' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return Order::query()->create([
            'number' => 'COUPON-'.uniqid(), 'store_id' => $store,
            'guest_session_id' => $session, 'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
            'subtotal' => 100, 'delivery_fee' => 15, 'tax' => 5,
            'discount' => 0, 'total' => 120,
        ]);
    }

    private function makeCoupon(string $code = 'FLOW20', int $limit = 0): void
    {
        PlatformRecord::query()->create([
            'resource' => 'coupons-offers', 'external_key' => $code,
            'status' => 'active', 'payload' => [
                'code' => $code, 'kind' => 'coupon', 'status' => 'active',
                'discountType' => 'percentage', 'discountScope' => 'subtotal',
                'value' => 20, 'maxDiscount' => 0, 'minimumOrder' => 0,
                'usageLimit' => $limit, 'perCustomerLimit' => 0,
                'zadSharePercent' => 100, 'familySharePercent' => 0,
                'driverSharePercent' => 0,
            ],
        ]);
    }

    private function request(string $session = 'test-guest-123', ?string $code = 'FLOW20'): Request
    {
        $request = Request::create('/api/v1/app/orders/1/coupon/apply', 'POST',
            $code === null ? [] : ['coupon_code' => $code]);
        $request->headers->set('X-Guest-Session', $session);
        $request->headers->set('Accept', 'application/json');
        return $request;
    }

    private function controller(): OrderCouponController
    {
        return app(OrderCouponController::class);
    }

    public function test_apply_updates_order_and_returns_correct_response(): void
    {
        $this->makeCoupon();
        $order = $this->makeOrder();
        $json = $this->controller()->apply($this->request(), $order)->getData(true);
        $this->assertSame('FLOW20', $json['data']['coupon']['code']);
        $this->assertEquals(20, $json['data']['coupon']['discount_amount']);
        $this->assertEquals(100, $json['data']['order']['total']);
        $this->assertEquals(100, $order->fresh()->total);
        $this->assertSame('applied', OrderCouponUsage::query()->where('order_id', $order->id)->firstOrFail()->status);
    }

    public function test_remove_restores_original_total_and_releases_coupon(): void
    {
        $this->makeCoupon();
        $order = $this->makeOrder();
        $this->controller()->apply($this->request(), $order);
        $json = $this->controller()->remove($this->request(), $order->fresh())->getData(true);
        $this->assertNull($json['data']['coupon']);
        $this->assertEquals(120, $json['data']['order']['total']);
        $this->assertEquals(0, $order->fresh()->discount);
        $this->assertSame('removed', OrderCouponUsage::query()->where('order_id', $order->id)->firstOrFail()->status);
    }

    public function test_other_guest_cannot_apply_coupon(): void
    {
        $this->makeCoupon();
        $order = $this->makeOrder();
        $this->expectException(HttpException::class);
        $this->controller()->apply($this->request('wrong-session'), $order);
    }

    public function test_paid_order_cannot_change_coupon(): void
    {
        $this->makeCoupon();
        $order = $this->makeOrder();
        $order->update(['payment_status' => Order::PAYMENT_PAID]);
        $this->expectException(HttpException::class);
        $this->controller()->apply($this->request(), $order->fresh());
    }

    public function test_usage_limit_blocks_second_order(): void
    {
        $this->makeCoupon('FLOW20', 1);
        $first = $this->makeOrder();
        $second = $this->makeOrder();
        $this->controller()->apply($this->request(), $first);
        $this->expectException(ValidationException::class);
        $this->controller()->apply($this->request(), $second);
    }
}
