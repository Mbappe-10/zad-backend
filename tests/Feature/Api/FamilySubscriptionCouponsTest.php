<?php

namespace Tests\Feature\Api;

use App\Models\AppProfile;
use App\Models\FamilyStoreGrant;
use App\Models\FamilyStorePurchase;
use App\Models\FamilySubscriptionCoupon;
use App\Models\PlatformControl;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FamilySubscriptionCouponsTest extends TestCase
{
    use RefreshDatabase;

    private ProductiveFamily $family;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2_contracts');
        $this->family = ProductiveFamily::query()->create([
            'code' => 'PF-CPN01', 'owner_name' => 'أسرة الكوبون',
            'phone' => '0551112222', 'status' => 'approved',
        ]);
        Store::query()->create([
            'productive_family_id' => $this->family->id, 'name_ar' => 'متجر الكوبون',
            'slug' => 'coupon-store', 'status' => 'active', 'is_open' => true,
        ]);
        $this->member = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $this->member->id, 'productive_family_id' => $this->family->id,
            'roles' => ['productive_family'], 'active_mode' => 'productive_family',
        ]);
        config([
            'zad_family_subscription_payment.enabled' => true,
            'moyasar.non_live_isolated' => true,
            'moyasar.test_publishable_key' => 'pk_test_fake',
            'moyasar.test_secret_key' => 'sk_test_fake',
        ]);
        PlatformControl::query()->create(['section' => 'family_subscriptions', 'value' => ['enabled' => true]]);
        PlatformControl::query()->create(['section' => 'services', 'value' => [
            'paymentGatewayEnabled' => true, 'paymentMode' => 'sandbox',
        ]]);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_owner' => true])->save();

        return $user;
    }

    private function coupon(array $attributes): FamilySubscriptionCoupon
    {
        return FamilySubscriptionCoupon::query()->create(array_replace([
            'code' => 'SAVE20', 'discount_type' => 'percentage', 'discount_value' => 20,
            'per_family_limit' => 1, 'is_active' => true,
        ], $attributes));
    }

    /** Fakes Moyasar answering "paid" for the latest attempt with its exact amount. */
    private function moyasarPays(): void
    {
        Http::fake(function () {
            $purchase = FamilyStorePurchase::query()->latest('id')->firstOrFail();

            return Http::response([
                'id' => $purchase->given_id, 'status' => 'paid',
                'amount' => $purchase->amount_halalas, 'currency' => 'SAR', 'live' => false,
                'metadata' => [
                    'family_store_purchase_id' => (string) $purchase->id,
                    'productive_family_id' => (string) $purchase->productive_family_id,
                    'environment' => 'sandbox',
                ],
            ]);
        });
    }

    private function buy(string $plan, ?string $coupon = null): array
    {
        Sanctum::actingAs($this->member);
        $data = $this->postJson('/api/v1/app/productive-family/storefront/purchases', array_filter([
            'plan' => $plan, 'coupon' => $coupon,
        ]))->assertSuccessful()->json('data');
        if ($data['status'] === 'pending') {
            $this->moyasarPays();
            $data = $this->postJson('/api/v1/app/productive-family/storefront/purchases/'.$data['id'].'/verify')
                ->assertOk()->json('data');
        }

        return $data;
    }

    public function test_percentage_coupon_prices_the_plan_on_the_server_and_is_counted_once_paid(): void
    {
        $this->coupon(['code' => 'SAVE20', 'discount_value' => 20]);
        Sanctum::actingAs($this->member);

        $this->postJson('/api/v1/app/productive-family/storefront/coupons/quote', ['plan' => 'silver', 'coupon' => 'save20'])
            ->assertOk()->assertJsonPath('data.original_halalas', 4900)
            ->assertJsonPath('data.discount_halalas', 980)->assertJsonPath('data.final_halalas', 3920);
        $this->postJson('/api/v1/app/productive-family/storefront/coupons/quote', ['plan' => 'silver', 'coupon' => 'NOPE'])
            ->assertStatus(422)->assertJsonValidationErrors('coupon');

        $paid = $this->buy('silver', ' save20 ');
        $this->assertSame('paid', $paid['status']);
        $this->assertSame(3920, $paid['amount_halalas']);
        $this->assertSame(980, $paid['discount_halalas']);
        $this->assertSame(1, FamilySubscriptionCoupon::query()->where('code', 'SAVE20')->value('redeemed_count'));
        $this->assertDatabaseHas('family_subscription_coupon_redemptions', ['status' => 'redeemed', 'discount_halalas' => 980]);

        // per_family_limit = 1
        $this->postJson('/api/v1/app/productive-family/storefront/coupons/quote', ['plan' => 'silver', 'coupon' => 'SAVE20'])
            ->assertStatus(422);
    }

    public function test_coupon_restricted_to_gold_does_not_apply_to_silver_and_never_works_for_orders(): void
    {
        $this->coupon(['code' => 'GOLDONLY', 'plan_codes' => ['gold']]);
        Sanctum::actingAs($this->member);
        $this->postJson('/api/v1/app/productive-family/storefront/coupons/quote', ['plan' => 'silver', 'coupon' => 'GOLDONLY'])
            ->assertStatus(422);
        $this->postJson('/api/v1/app/productive-family/storefront/coupons/quote', ['plan' => 'gold', 'coupon' => 'GOLDONLY'])
            ->assertOk();
        $this->assertDatabaseCount('order_coupon_usages', 0);
    }

    public function test_full_discount_coupon_activates_immediately_without_contacting_moyasar(): void
    {
        Http::fake();
        $this->coupon(['code' => 'FREEGOLD', 'discount_value' => 100, 'plan_codes' => ['gold']]);
        Sanctum::actingAs($this->member);

        $data = $this->postJson('/api/v1/app/productive-family/storefront/purchases', ['plan' => 'gold', 'coupon' => 'FREEGOLD'])
            ->assertSuccessful()->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount_halalas', 0)->assertJsonPath('data.mode', 'coupon')->json('data');
        Http::assertNothingSent();
        $this->getJson('/api/v1/app/productive-family/storefront/purchases/latest')
            ->assertOk()->assertJsonPath('entitlement.plan', 'gold');
        $receipt = $this->getJson('/api/v1/app/productive-family/storefront/purchases/'.$data['id'].'/receipt')->assertOk()->json();
        $this->assertStringStartsWith('%PDF-', base64_decode($receipt['pdf_base64']));
    }

    public function test_small_remainder_is_charged_at_least_one_riyal(): void
    {
        $this->coupon(['code' => 'ALMOST', 'discount_type' => 'fixed', 'discount_value' => 48.5]);
        Sanctum::actingAs($this->member);
        $this->postJson('/api/v1/app/productive-family/storefront/coupons/quote', ['plan' => 'silver', 'coupon' => 'ALMOST'])
            ->assertOk()->assertJsonPath('data.final_halalas', 100)->assertJsonPath('data.discount_halalas', 4800);
    }

    public function test_upgrade_to_gold_applies_now_and_converts_remaining_silver_days(): void
    {
        $this->buy('silver');
        $this->travel(10)->days();
        $gold = $this->buy('gold');

        $this->getJson('/api/v1/app/productive-family/storefront/purchases/latest')
            ->assertOk()->assertJsonPath('entitlement.plan', 'gold');
        $grant = FamilyStoreGrant::query()->findOrFail(FamilyStorePurchase::query()->findOrFail($gold['id'])->grant_id);
        $this->assertTrue($grant->starts_at->between(now()->subMinute(), now()->addMinute()));
        // 20 silver days left at 49/30 per day become 20 * 49 / 99 ≈ 9.9 gold days on top of 30.
        $days = $grant->starts_at->diffInSeconds($grant->ends_at) / 86400;
        $this->assertEqualsWithDelta(30 + 20 * 49 / 99, $days, 0.05);
        $silver = FamilyStoreGrant::query()->where('plan_code', 'silver')->firstOrFail();
        $this->assertTrue($silver->ends_at->lte(now()->addMinute()));
    }

    public function test_renewing_the_same_plan_is_queued_after_the_current_one(): void
    {
        $this->buy('silver');
        $second = $this->buy('silver');
        $grants = FamilyStoreGrant::query()->orderBy('id')->get();
        $this->assertCount(2, $grants);
        $this->assertTrue($grants[1]->starts_at->equalTo($grants[0]->ends_at));
        $this->assertSame('paid', $second['status']);
    }

    public function test_family_can_cancel_and_returns_to_free(): void
    {
        $this->buy('silver');
        Sanctum::actingAs($this->member);
        $this->postJson('/api/v1/app/productive-family/storefront/subscription/cancel', [])->assertStatus(422);
        $this->postJson('/api/v1/app/productive-family/storefront/subscription/cancel', ['confirm' => true])
            ->assertOk()->assertJsonPath('entitlement.plan', 'free');
        $this->assertDatabaseHas('family_store_grants', ['revoked_by_role' => 'family']);
    }

    public function test_owner_lists_families_grants_hours_and_cancels_paid_plans_with_a_reason(): void
    {
        $paid = $this->buy('silver');
        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/family-store-families?search=الكوبون')
            ->assertOk()->assertJsonPath('data.data.0.id', $this->family->id)
            ->assertJsonPath('data.data.0.store_name', 'متجر الكوبون')
            ->assertJsonPath('data.data.0.plan', 'silver');

        $grant = $this->postJson("/api/admin/families/{$this->family->id}/subscription/grants", [
            'plan' => 'gold', 'duration_unit' => 'hours', 'duration_value' => 5, 'reason' => 'تجربة',
        ])->assertCreated()->assertJsonPath('entitlement.plan', 'gold')->json('data');
        $this->assertEqualsWithDelta(5 * 3600, strtotime($grant['ends_at']) - strtotime($grant['starts_at']), 2);

        $paidGrant = FamilyStorePurchase::query()->findOrFail($paid['id'])->grant_id;
        $this->postJson("/api/admin/families/{$this->family->id}/subscription/grants/{$paidGrant}/revoke")
            ->assertStatus(422);
        $this->postJson("/api/admin/families/{$this->family->id}/subscription/grants/{$paidGrant}/revoke", ['reason' => 'استرداد من ميسر'])
            ->assertOk();
        $this->postJson("/api/admin/families/{$this->family->id}/subscription/cancel", ['reason' => 'إيقاف'])
            ->assertOk()->assertJsonPath('entitlement.plan', 'free');
    }

    public function test_owner_manages_coupons_and_receipt_is_publicly_verifiable(): void
    {
        Sanctum::actingAs($this->owner());
        $coupon = $this->postJson('/api/admin/family-subscription-coupons', [
            'code' => 'ramadan', 'discount_type' => 'percentage', 'discount_value' => 50,
            'plan_codes' => ['silver'], 'max_redemptions' => 10,
        ])->assertCreated()->assertJsonPath('data.code', 'RAMADAN')->json('data');
        $this->postJson('/api/admin/family-subscription-coupons', [
            'code' => 'RAMADAN', 'discount_type' => 'fixed', 'discount_value' => 5,
        ])->assertStatus(422);
        $this->patchJson('/api/admin/family-subscription-coupons/'.$coupon['id'], ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $paid = $this->buy('silver');
        $purchase = FamilyStorePurchase::query()->findOrFail($paid['id']);
        $this->getJson('/api/v1/family-subscription-receipts/verify?number='.$purchase->receipt_number.'&fp='.substr($purchase->receipt_hash, 0, 16))
            ->assertOk()->assertJsonPath('valid', true);
        $this->getJson('/api/v1/family-subscription-receipts/verify?number='.$purchase->receipt_number.'&fp=0000000000000000')
            ->assertNotFound();
    }
}
