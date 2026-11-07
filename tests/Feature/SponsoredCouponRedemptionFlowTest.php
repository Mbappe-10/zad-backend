<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SponsoredCreatorCoupon;
use App\Services\SponsoredCouponRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SponsoredCouponRedemptionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDefaultConnection() !== "sqlite"
            || DB::connection()->getDatabaseName() !== ":memory:") {
            throw new \RuntimeException("SQLite memory required.");
        }
    }

    private function persistedMarker(): Order
    {
        $order = new Order();
        $order->exists = true;
        $order->id = 999999;
        return $order;
    }

    public function test_rejects_unsaved_order(): void
    {
        DB::transaction(function (): void {
            $this->expectException(\LogicException::class);
            app(SponsoredCouponRedemptionService::class)->redeem(
                "TEST", new Order(), [], 1000, 0, 0, "unsaved-order"
            );
        });
    }

    public function test_rejects_invalid_amount(): void
    {
        DB::transaction(function (): void {
            $this->expectException(ValidationException::class);
            app(SponsoredCouponRedemptionService::class)->redeem(
                "TEST", $this->persistedMarker(), [], -100, 0, 0, "invalid-amount"
            );
        });
    }

    public function test_rejects_invalid_idempotency_key(): void
    {
        DB::transaction(function (): void {
            $this->expectException(ValidationException::class);
            app(SponsoredCouponRedemptionService::class)->redeem(
                "TEST", $this->persistedMarker(), [], 1000, 0, 0, ""
            );
        });
    }

    public function test_no_funding_created_for_invalid_code(): void
    {
        $this->assertSame(0, DB::table("sponsored_order_fundings")->count());
        $this->assertSame(0, SponsoredCreatorCoupon::query()->count());
    }
}