<?php

namespace Tests\Feature\Api;

use App\Models\AppProfile;
use App\Models\ProductiveFamily;
use App\Models\PlatformControl;
use App\Models\FamilyStorePurchase;
use App\Models\FamilyStoreGrant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FamilyStoreSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private function family(): array
    {
        $family = ProductiveFamily::query()->create([
            'code' => 'PF-TEST01', 'owner_name' => 'أسرة الاختبار',
            'phone' => '0551234567', 'status' => 'approved',
            'metadata' => ['subscription_plan' => 'gold'],
        ]);
        $store = Store::query()->create([
            'productive_family_id' => $family->id, 'name_ar' => 'متجر الأسرة',
            'slug' => 'test-family-store', 'status' => 'active', 'is_open' => true,
            'cover_path' => 'https://example.test/banner.jpg',
            'storefront_design' => ['theme' => 'warm'],
        ]);

        return [$family, $store];
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_owner' => true])->save();

        return $user;
    }

    public function test_legacy_metadata_does_not_grant_premium_and_only_owner_can_grant(): void
    {
        [$family, $store] = $this->family();
        $ordinary = User::factory()->create();
        Sanctum::actingAs($ordinary);
        $this->postJson("/api/admin/families/{$family->id}/subscription/grants", [
            'plan' => 'gold', 'days' => 100,
        ])->assertForbidden();

        $this->getJson("/api/v1/app/stores/{$store->id}/products")
            ->assertOk()->assertJsonPath('store.plan', 'free')
            ->assertJsonPath('store.cover_url', null)
            ->assertJsonPath('store.design.theme', 'default');
    }

    public function test_owner_grant_expires_to_free_without_deleting_design(): void
    {
        [$family, $store] = $this->family();
        Sanctum::actingAs($this->owner());
        $grant = $this->postJson("/api/admin/families/{$family->id}/subscription/grants", [
            'plan' => 'gold', 'days' => 7, 'reason' => 'تجربة مجانية',
        ])->assertCreated()->assertJsonPath('entitlement.plan', 'gold')->json('data');
        $this->getJson("/api/v1/app/stores/{$store->id}/products")
            ->assertJsonPath('store.design.theme', 'warm')
            ->assertJsonPath('store.cover_url', 'https://example.test/banner.jpg');

        $this->travel(8)->days();
        $this->getJson("/api/v1/app/stores/{$store->id}/products")
            ->assertJsonPath('store.plan', 'free')
            ->assertJsonPath('store.cover_url', null)
            ->assertJsonPath('store.design.theme', 'default');
        $this->assertSame('warm', $store->fresh()->storefront_design['theme']);
    }

    public function test_family_preview_and_editor_enforce_current_capabilities(): void
    {
        [$family, $store] = $this->family();
        $member = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $member->id,
            'productive_family_id' => $family->id,
            'roles' => ['productive_family'],
            'active_mode' => 'productive_family',
        ]);
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/app/productive-family/storefront')
            ->assertOk()->assertJsonPath('store.plan', 'free')
            ->assertJsonPath('draft_design.theme', 'warm');
        $this->patchJson('/api/v1/app/productive-family/storefront', ['theme' => 'modern'])
            ->assertForbidden();
        $this->patchJson('/api/v1/app/productive-family/storefront', ['description_ar' => 'وصف جديد'])
            ->assertOk();
        $this->assertSame('وصف جديد', $store->fresh()->description_ar);
    }

    public function test_owner_can_edit_plan_and_revoke_an_arbitrary_day_grant(): void
    {
        [$family, $store] = $this->family();
        Sanctum::actingAs($this->owner());
        $this->patchJson('/api/admin/family-store-plans/silver', [
            'price' => 39,
            'features' => [['id' => 'weekly', 'title' => 'ترويج أسبوعي', 'description' => 'منتجان']],
            'capabilities' => ['themes' => true, 'weekly_promotions' => 2],
        ])->assertOk()->assertJsonPath('data.price', '39.00');
        $grant = $this->postJson("/api/admin/families/{$family->id}/subscription/grants", [
            'plan' => 'silver', 'days' => 1000,
        ])->assertCreated()->json('data');
        $this->getJson("/api/v1/app/stores/{$store->id}/products")
            ->assertJsonPath('store.plan', 'silver');
        $this->postJson("/api/admin/families/{$family->id}/subscription/grants/{$grant['id']}/revoke")
            ->assertOk()->assertJsonPath('entitlement.plan', 'free');
        $this->getJson("/api/v1/app/stores/{$store->id}/products")
            ->assertJsonPath('store.plan', 'free');
    }

    public function test_subscription_is_activated_only_after_provider_invoice_verification_and_only_once(): void
    {
        [$family] = $this->family();
        $member = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $member->id,
            'productive_family_id' => $family->id,
            'roles' => ['productive_family'],
            'active_mode' => 'productive_family',
        ]);
        Sanctum::actingAs($member);
        config([
            'moyasar.non_live_isolated' => true,
            'moyasar.test_publishable_key' => 'pk_test_fake',
            'moyasar.test_secret_key' => 'sk_test_fake',
        ]);
        PlatformControl::query()->create(['section' => 'family_subscriptions', 'value' => ['enabled' => true]]);
        PlatformControl::query()->create(['section' => 'services', 'value' => [
            'paymentGatewayEnabled' => true, 'paymentMode' => 'sandbox',
        ]]);
        $invoiceId = 'b778fefa-78d2-4f1d-81e5-e36ba29fd132';
        $paymentId = 'e6678436-c540-4078-93d5-505d3852c031';
        $callback = null;
        $paid = false;
        Http::fake(function ($request) use ($invoiceId, $paymentId, &$callback, &$paid) {
            if ($request->method() === 'POST') {
                $callback = $request['callback_url'];
                return Http::response([
                    'id' => $invoiceId,
                    'status' => 'initiated',
                    'amount' => 4900,
                    'currency' => 'SAR',
                    'url' => 'https://checkout.moyasar.com/invoices/'.$invoiceId,
                ], 201);
            }
            return Http::response([
                'id' => $invoiceId,
                'status' => $paid ? 'paid' : 'initiated',
                'amount' => 4900,
                'currency' => 'SAR',
                'payments' => $paid ? [[
                    'id' => $paymentId, 'invoice_id' => $invoiceId,
                    'amount' => 4900, 'status' => 'paid',
                ]] : [],
            ]);
        });

        $this->getJson('/api/v1/app/productive-family/storefront/plans')
            ->assertOk()->assertJsonPath('purchase_available', true);
        $first = $this->postJson('/api/v1/app/productive-family/storefront/purchases', ['plan' => 'silver'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertDatabaseCount('family_store_grants', 0);
        $this->postJson(parse_url($callback, PHP_URL_PATH), ['status' => 'paid'])
            ->assertOk(); // A forged callback alone cannot grant a plan.
        $this->assertDatabaseCount('family_store_grants', 0);
        $this->postJson('/api/v1/app/productive-family/storefront/purchases/'.$first['id'].'/verify')
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $paid = true;
        $this->postJson('/api/v1/app/productive-family/storefront/purchases/'.$first['id'].'/verify')
            ->assertOk()->assertJsonPath('entitlement.plan', 'silver');
        $this->postJson('/api/v1/app/productive-family/storefront/purchases/'.$first['id'].'/verify')
            ->assertOk();
        $this->assertDatabaseCount('family_store_grants', 1);
        $this->assertDatabaseHas('family_store_purchases', [
            'id' => $first['id'], 'status' => 'paid', 'provider_payment_id' => $paymentId,
        ]);
    }

    public function test_subscription_payment_cannot_be_activated_by_a_different_amount(): void
    {
        [$family] = $this->family();
        $member = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $member->id, 'productive_family_id' => $family->id,
            'roles' => ['productive_family'], 'active_mode' => 'productive_family',
        ]);
        Sanctum::actingAs($member);
        config([
            'moyasar.non_live_isolated' => true,
            'moyasar.test_publishable_key' => 'pk_test_fake',
            'moyasar.test_secret_key' => 'sk_test_fake',
        ]);
        PlatformControl::query()->create(['section' => 'family_subscriptions', 'value' => ['enabled' => true]]);
        PlatformControl::query()->create(['section' => 'services', 'value' => [
            'paymentGatewayEnabled' => true, 'paymentMode' => 'sandbox',
        ]]);
        $invoiceId = 'b778fefa-78d2-4f1d-81e5-e36ba29fd132';
        Http::fake(function ($request) use ($invoiceId) {
            return $request->method() === 'POST'
                ? Http::response([
                    'id' => $invoiceId, 'amount' => 4900, 'currency' => 'SAR',
                    'url' => 'https://checkout.moyasar.com/invoices/'.$invoiceId,
                ], 201)
                : Http::response([
                    'id' => $invoiceId, 'status' => 'paid', 'amount' => 1,
                    'currency' => 'SAR', 'payments' => [],
                ]);
        });
        $purchase = $this->postJson('/api/v1/app/productive-family/storefront/purchases', ['plan' => 'silver'])
            ->assertCreated()->json('data');
        $this->postJson('/api/v1/app/productive-family/storefront/purchases/'.$purchase['id'].'/verify')
            ->assertStatus(422);
        $this->assertDatabaseCount('family_store_grants', 0);
        $this->assertSame('pending', FamilyStorePurchase::query()->findOrFail($purchase['id'])->status);
    }

    public function test_revoking_a_gift_moves_a_queued_paid_plan_forward_without_losing_its_days(): void
    {
        [$family] = $this->family();
        Sanctum::actingAs($this->owner());
        $gift = FamilyStoreGrant::query()->create([
            'productive_family_id' => $family->id, 'plan_code' => 'gold',
            'source' => 'owner_grant', 'starts_at' => now(), 'ends_at' => now()->addDays(7),
        ]);
        $paid = FamilyStoreGrant::query()->create([
            'productive_family_id' => $family->id, 'plan_code' => 'silver',
            'source' => 'purchase', 'starts_at' => $gift->ends_at,
            'ends_at' => $gift->ends_at->copy()->addDays(30),
        ]);

        $this->postJson("/api/admin/families/{$family->id}/subscription/grants/{$gift->id}/revoke")
            ->assertOk()->assertJsonPath('entitlement.plan', 'silver');
        $this->assertTrue($paid->fresh()->starts_at->between(now()->subMinute(), now()->addMinute()));
        $this->assertSame(30, (int) round($paid->fresh()->starts_at->diffInDays($paid->fresh()->ends_at)));
    }
}
