<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\SponsoredCouponRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SponsoredCouponRedemptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDefaultConnection() !== "sqlite" || DB::connection()->getDatabaseName() !== ":memory:") {
            throw new \RuntimeException("SQLite memory database required.");
        }
    }

    public function test_redemption_requires_outer_transaction(): void
    {
        $this->expectException(\LogicException::class);

        app(SponsoredCouponRedemptionService::class)->redeem(
            "INVALID-CODE",
            new Order(),
            [["product_id" => 1, "quantity" => 1]],
            2000,
            500,
            0,
            "test-idempotency-key"
        );
    }
}