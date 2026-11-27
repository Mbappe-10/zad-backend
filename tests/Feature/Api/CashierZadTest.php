<?php

namespace Tests\Feature\Api;

use App\Models\AppProfile;
use App\Models\FamilyCashierGrant;
use App\Models\FamilyStoreGrant;
use App\Models\FamilyStorePurchase;
use App\Models\PlatformControl;
use App\Models\Product;
use App\Models\ProductiveFamily;
use App\Models\SaleReceipt;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashierZadTest extends TestCase
{
    use RefreshDatabase;

    private ProductiveFamily $family;
    private Store $store;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2_contracts');
        $this->family = ProductiveFamily::query()->create([
            'code' => 'PF-CASH1', 'owner_name' => 'أسرة الكاشير', 'phone' => '0551113333', 'status' => 'approved',
        ]);
        $this->store = Store::query()->create([
            'productive_family_id' => $this->family->id, 'name_ar' => 'سوبو',
            'slug' => 'cashier-store', 'status' => 'active', 'is_open' => true,
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
        PlatformControl::query()->create(['section' => 'services', 'value' => ['paymentGatewayEnabled' => true, 'paymentMode' => 'sandbox']]);
        Sanctum::actingAs($this->member);
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->forceFill(['is_platform_owner' => true])->save();

        return $owner;
    }

    private function gold(): void
    {
        FamilyStoreGrant::query()->create([
            'productive_family_id' => $this->family->id, 'plan_code' => 'gold', 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(10),
        ]);
    }

    private function sale(array $extra = []): \Illuminate\Testing\TestResponse
    {
        $product = Product::query()->forceCreate([
            'store_id' => $this->store->id, 'name_ar' => 'حبحبوه', 'price' => 25, 'status' => 'active',
            'is_available' => true, 'preparation_minutes' => 10, 'package_size' => 'small',
        ]);

        return $this->postJson('/api/v1/app/family/cashier/sales', array_replace([
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'network', 'channel' => 'shop',
        ], $extra));
    }

    private function sellingOn(): void
    {
        \App\Models\FamilyStorePlan::query()->where('code', 'cashier_pro')->update(['is_visible' => true]);
    }

    public function test_pro_is_priced_at_19_by_default_and_free_family_has_no_cashier_yet(): void
    {
        $this->getJson('/api/v1/app/family/cashier')->assertOk()
            ->assertJsonPath('data.tier', 'none')->assertJsonPath('data.price', '19.00')
            ->assertJsonPath('data.can_buy_pro', false)->assertJsonPath('data.features.quick_sale', false);
        $this->postJson('/api/v1/app/productive-family/storefront/purchases', ['plan' => 'cashier_pro'])->assertForbidden();
        $this->sellingOn();
        $this->getJson('/api/v1/app/family/cashier')->assertJsonPath('data.can_buy_pro', true);
        $this->sale()->assertForbidden();
        // Store plans page never lists Cashier Pro.
        $plans = collect($this->getJson('/api/v1/app/productive-family/storefront/plans')->json('plans'))->pluck('code');
        $this->assertNotContains('cashier_pro', $plans);
    }

    public function test_gold_gets_basic_cashier_quick_sale_issues_a_receipt_and_shows_on_the_customer_screen(): void
    {
        $this->gold();
        $this->getJson('/api/v1/app/family/cashier')->assertJsonPath('data.tier', 'basic')
            ->assertJsonPath('data.features.quick_sale', true)->assertJsonPath('data.features.batches', false);

        $receipt = $this->sale(['customer_phone' => '0551234812'])->assertCreated()
            ->assertJsonPath('data.sale_type', 'quick')->assertJsonPath('data.total', 50)
            ->assertJsonPath('data.payment_method_label', 'شبكة (مدى)')->json('data');
        $this->assertStringStartsWith('ZS-M-', $receipt['number']);
        $this->getJson('/api/v1/app/family/cashier/display')->assertOk()
            ->assertJsonPath('data.number', $receipt['number'])->assertJsonPath('data.url', $receipt['url']);
        $this->getJson('/api/v1/app/family/cashier/day')->assertOk()
            ->assertJsonPath('data.direct_total', 50)->assertJsonPath('data.methods.0.method', 'network');
        $this->get(parse_url($receipt['url'], PHP_URL_PATH))->assertOk()->assertSee('سوبو');

        $this->travel(4)->minutes();
        $this->getJson('/api/v1/app/family/cashier/display')->assertJsonPath('data', null);

        // The owner moves the basic cashier to silver only: gold loses it.
        Sanctum::actingAs($this->owner());
        $this->patchJson('/api/admin/cashier-zad/settings', ['basic_for' => ['silver']])->assertOk()
            ->assertJsonPath('data.basic_for', ['silver']);
        Sanctum::actingAs($this->member);
        $this->getJson('/api/v1/app/family/cashier')->assertJsonPath('data.tier', 'none');
    }

    public function test_free_family_buys_pro_with_a_full_coupon_and_owner_edits_price_and_features(): void
    {
        Http::fake();
        $this->sellingOn();
        \App\Models\FamilySubscriptionCoupon::query()->create([
            'code' => 'CASHFREE', 'discount_type' => 'percentage', 'discount_value' => 100,
            'per_family_limit' => 1, 'is_active' => true, 'plan_codes' => ['cashier_pro'],
        ]);
        $this->postJson('/api/v1/app/productive-family/storefront/purchases', ['plan' => 'cashier_pro', 'coupon' => 'CASHFREE'])
            ->assertSuccessful()->assertJsonPath('data.status', 'paid');
        Http::assertNothingSent();
        $grant = FamilyCashierGrant::query()->firstOrFail();
        $this->assertSame('purchase', $grant->source);
        $this->assertEqualsWithDelta(30, $grant->starts_at->diffInSeconds($grant->ends_at) / 86400, 0.01);
        $this->assertNull(FamilyStorePurchase::query()->firstOrFail()->grant_id);
        $this->getJson('/api/v1/app/family/cashier')->assertJsonPath('data.tier', 'pro')->assertJsonPath('data.features.batches', true);
        // The store plan stays free.
        $this->getJson('/api/v1/app/productive-family/storefront/status')->assertJsonPath('data.plan', 'free');
        // Pro may sell an item that is not in the store.
        $this->sale(['items' => [['name' => 'كيس ثلج', 'unit_price' => 3, 'quantity' => 1]]])->assertCreated()->assertJsonPath('data.total', 3);

        Sanctum::actingAs($this->owner());
        $this->patchJson('/api/admin/cashier-zad/settings', [
            'price' => 20, 'features' => ['batches' => ['pro' => false]],
            'launch_offer' => ['enabled' => true, 'plan' => 'gold', 'days' => 90],
        ])->assertOk()->assertJsonPath('data.price', '20.00')->assertJsonPath('data.features.batches.pro', false)
            ->assertJsonPath('data.launch_offer.enabled', true);
        $this->getJson('/api/admin/cashier-zad/subscribers')->assertOk()->assertJsonPath('summary.pro', 1);

        Sanctum::actingAs($this->member);
        $this->getJson('/api/v1/app/family/cashier')->assertJsonPath('data.price', '20.00')->assertJsonPath('data.features.batches', false);
    }

    public function test_launch_offer_once_for_gold_and_owner_grant_and_revoke(): void
    {
        Sanctum::actingAs($this->owner());
        $this->patchJson('/api/admin/cashier-zad/settings', ['launch_offer' => ['enabled' => true, 'plan' => 'gold', 'days' => 90]])->assertOk();
        Sanctum::actingAs($this->member);
        $this->postJson('/api/v1/app/family/cashier/launch-offer')->assertStatus(422);

        $this->gold();
        $this->getJson('/api/v1/app/family/cashier')->assertJsonPath('data.launch_offer.days', 90);
        $this->postJson('/api/v1/app/family/cashier/launch-offer')->assertOk();
        $this->postJson('/api/v1/app/family/cashier/launch-offer')->assertStatus(422);
        $this->getJson('/api/v1/app/family/cashier')->assertJsonPath('data.tier', 'pro')->assertJsonPath('data.launch_offer', null);

        Sanctum::actingAs($this->owner());
        $this->postJson('/api/admin/cashier-zad/grants', [
            'productive_family_id' => $this->family->id, 'duration' => 5, 'duration_unit' => 'days', 'reason' => 'تعويض',
        ])->assertCreated();
        $extra = FamilyCashierGrant::query()->where('source', 'owner_grant')->firstOrFail();
        $first = FamilyCashierGrant::query()->where('source', 'launch_offer')->firstOrFail();
        $this->assertTrue($extra->starts_at->equalTo($first->ends_at));
        $this->postJson("/api/admin/cashier-zad/grants/{$first->id}/revoke", ['reason' => 'خطأ'])->assertOk();
        $this->postJson('/api/admin/cashier-zad/grants', [
            'productive_family_id' => $this->family->id, 'duration' => 5, 'duration_unit' => 'days',
        ])->assertStatus(422);

        Sanctum::actingAs($this->member);
        $this->getJson('/api/admin/cashier-zad/settings')->assertForbidden();
    }
}
