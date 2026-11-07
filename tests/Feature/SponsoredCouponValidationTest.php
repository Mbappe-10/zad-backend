<?php

namespace Tests\Feature;

use App\Models\SponsoredCreatorCoupon;
use App\Models\User;
use App\Services\SponsoredCreatorCouponService;
use App\Services\SponsoredCouponValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SponsoredCouponValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDefaultConnection() !== "sqlite" || DB::connection()->getDatabaseName() !== ":memory:") {
            throw new \RuntimeException("SQLite memory database required.");
        }
    }

    private function fixture(): array
    {
        $tag = bin2hex(random_bytes(6));
        $owner = User::factory()->create();
        $owner->is_platform_owner = true;
        $owner->save();
        $owner = $owner->fresh();

        $familyId = DB::table("productive_families")->insertGetId([
            "code" => "SVF-" . $tag,
            "owner_name" => "Test Family",
            "phone" => "05" . random_int(10000000, 99999999),
            "status" => "approved",
            "created_at" => now(),
            "updated_at" => now(),
        ]);

        $storeId = DB::table("stores")->insertGetId([
            "productive_family_id" => $familyId,
            "name_ar" => "Sponsored Validation Store",
            "slug" => "sv-store-" . $tag,
            "status" => "active",
            "created_at" => now(),
            "updated_at" => now(),
        ]);

        $creatorId = DB::table("launch_creators")->insertGetId([
            "code" => "SVC-" . $tag,
            "name" => "Sponsored Validation Creator",
            "status" => "active",
            "created_at" => now(),
            "updated_at" => now(),
        ]);

        $productId = DB::table("products")->insertGetId([
            "store_id" => $storeId,
            "name_ar" => "Sponsored Test Product",
            "price" => 20,
            "status" => "active",
            "created_at" => now(),
            "updated_at" => now(),
        ]);

        $service = app(SponsoredCreatorCouponService::class);
        $result = $service->createDraft($owner, [
            "creator_id" => $creatorId,
            "store_id" => $storeId,
            "allowed_products" => [[
                "product_id" => $productId,
                "max_quantity" => 2,
            ]],
            "gross_cap" => 60,
            "display_name" => "تجربة بلوغر مكة",
            "coupon_code" => "zad620",
        ]);

        return [
            "owner" => $owner,
            "store" => $storeId,
            "product" => $productId,
            "coupon" => $result["coupon"],
            "code" => $result["one_time_plain_code"],
        ];
    }

    private function check(array $f, ?array $items = null, int $cents = 2000, ?int $store = null): SponsoredCreatorCoupon
    {
        return app(SponsoredCouponValidationService::class)->validateCoupon(
            $f["code"],
            $store ?? $f["store"],
            $items ?? [["product_id" => $f["product"], "quantity" => 1]],
            $cents
        );
    }

    public function test_active_coupon_is_accepted(): void
    {
        $f = $this->fixture();
        app(SponsoredCreatorCouponService::class)->activate($f["owner"], $f["coupon"]->id, 15);
        $this->assertSame($f["coupon"]->id, $this->check($f)->id);
    }

    public function test_draft_coupon_is_rejected(): void
    {
        $f = $this->fixture();
        $this->expectException(ValidationException::class);
        $this->check($f);
    }

    public function test_expired_coupon_is_rejected(): void
    {
        $f = $this->fixture();
        app(SponsoredCreatorCouponService::class)->activate($f["owner"], $f["coupon"]->id);
        $f["coupon"]->expires_at = now()->subMinute();
        $f["coupon"]->save();
        $this->expectException(ValidationException::class);
        $this->check($f);
    }

    public function test_wrong_store_is_rejected(): void
    {
        $f = $this->fixture();
        app(SponsoredCreatorCouponService::class)->activate($f["owner"], $f["coupon"]->id);
        $this->expectException(ValidationException::class);
        $this->check($f, null, 2000, $f["store"] + 100000);
    }

    public function test_unapproved_product_is_rejected(): void
    {
        $f = $this->fixture();
        app(SponsoredCreatorCouponService::class)->activate($f["owner"], $f["coupon"]->id);
        $this->expectException(ValidationException::class);
        $this->check($f, [["product_id" => $f["product"] + 100000, "quantity" => 1]]);
    }

    public function test_excess_quantity_is_rejected(): void
    {
        $f = $this->fixture();
        app(SponsoredCreatorCouponService::class)->activate($f["owner"], $f["coupon"]->id);
        $this->expectException(ValidationException::class);
        $this->check($f, [["product_id" => $f["product"], "quantity" => 3]]);
    }

    public function test_excess_gross_cap_is_rejected(): void
    {
        $f = $this->fixture();
        app(SponsoredCreatorCouponService::class)->activate($f["owner"], $f["coupon"]->id);
        $this->expectException(ValidationException::class);
        $this->check($f, null, 6001);
    }
    public function test_manual_code_is_redeemable_case_insensitively(): void
    {
        $f = $this->fixture();
        $coupon = $f["coupon"]->fresh();
        $this->assertSame("تجربة بلوغر مكة", $coupon->display_name);
        $this->assertSame("ZAD620", $coupon->code_label);
        $this->assertSame("ZAD620", $f["code"]);
        $this->assertSame(hash("sha256", "ZAD620"), $coupon->code_hash);

        app(SponsoredCreatorCouponService::class)->activate($f["owner"], $coupon->id, 5);
        $validated = app(SponsoredCouponValidationService::class)->validateCoupon(
            "zad620", $f["store"],
            [["product_id" => $f["product"], "quantity" => 1]], 2000
        );
        $this->assertSame($coupon->id, $validated->id);
    }
}
