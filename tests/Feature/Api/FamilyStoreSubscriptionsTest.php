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
use Illuminate\Support\Facades\Storage;
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

    public function test_family_payment_switch_is_independent_of_order_web_intents(): void
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
        app()->detectEnvironment(static fn (): string => 'production');
        config([
            'app.url' => 'https://zad-backend-w2a2.onrender.com',
            'moyasar.live_publishable_key' => 'pk_live_fake',
            'moyasar.live_secret_key' => 'sk_live_fake',
            'zad_web_payment.enabled' => false,
            'zad_family_subscription_payment.enabled' => true,
        ]);
        PlatformControl::query()->create(['section' => 'family_subscriptions', 'value' => ['enabled' => true]]);
        PlatformControl::query()->create(['section' => 'services', 'value' => [
            'paymentGatewayEnabled' => true, 'paymentMode' => 'live',
        ]]);

        $this->getJson('/api/v1/app/productive-family/storefront/plans')
            ->assertOk()->assertJsonPath('purchase_available', true)
            ->assertJsonPath('web_purchase_available', true);
        $purchase = $this->postJson('/api/v1/app/productive-family/storefront/purchases', ['plan' => 'silver'])
            ->assertCreated()->assertJsonPath('data.payment_config.web_checkout_available', true)->json('data');
        $intentId = '8f4d1c3e-6a52-4f0b-9a1e-2c7b5d8e0f31';
        Http::fake(['https://api.moyasar.com/v1/payment_intents' => Http::response([
            'id' => $intentId,
            'client_secret' => 'pi_secret_fake',
            'status' => 'created',
            'amount' => $purchase['amount_halalas'],
            'currency' => 'SAR',
            'callback_url' => 'https://zad-sync-sa.onrender.com/zad-family-subscription-return.html',
            'expires_at' => now()->addHour()->toISOString(),
        ], 201)]);
        $this->postJson('/api/v1/app/productive-family/storefront/purchases/'.$purchase['id'].'/web-intent', [
            'given_id' => $purchase['given_id'],
        ])->assertOk()->assertJsonPath('data.intent_id', $intentId);

        config(['zad_web_payment.enabled' => true, 'zad_family_subscription_payment.enabled' => false]);
        $this->getJson('/api/v1/app/productive-family/storefront/plans')
            ->assertOk()->assertJsonPath('purchase_available', false)
            ->assertJsonPath('web_purchase_available', false);
        $this->postJson('/api/v1/app/productive-family/storefront/purchases/'.$purchase['id'].'/web-intent', [
            'given_id' => $purchase['given_id'],
        ])->assertStatus(503);
    }

    public function test_subscription_is_activated_only_after_provider_payment_verification_and_receipt_is_immutable(): void
    {
        Storage::fake('r2_contracts');
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
            'zad_family_subscription_payment.enabled' => true,
            'moyasar.non_live_isolated' => true,
            'moyasar.test_publishable_key' => 'pk_test_fake',
            'moyasar.test_secret_key' => 'sk_test_fake',
        ]);
        PlatformControl::query()->create(['section' => 'family_subscriptions', 'value' => ['enabled' => true]]);
        PlatformControl::query()->create(['section' => 'services', 'value' => [
            'paymentGatewayEnabled' => true, 'paymentMode' => 'sandbox',
        ]]);
        $paid = false;
        Http::fake(function () use (&$paid) {
            $purchase = FamilyStorePurchase::query()->latest('id')->firstOrFail();
            return Http::response([
                'id' => $purchase->given_id,
                'status' => $paid ? 'paid' : 'initiated',
                'amount' => 4900,
                'currency' => 'SAR',
                'live' => false,
                'metadata' => [
                    'family_store_purchase_id' => (string) $purchase->id,
                    'productive_family_id' => (string) $purchase->productive_family_id,
                    'environment' => 'sandbox',
                ],
            ]);
        });

        $this->getJson('/api/v1/app/productive-family/storefront/plans')
            ->assertOk()->assertJsonPath('purchase_available', true);
        $first = $this->postJson('/api/v1/app/productive-family/storefront/purchases', ['plan' => 'silver'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertSame('pk_test_fake', $first['payment_config']['publishable_key']);
        $this->assertDatabaseCount('family_store_grants', 0);
        $this->postJson('/api/v1/app/family-store-purchases/webhook', ['type' => 'payment_paid', 'data' => ['id' => $first['given_id']]])
            ->assertUnauthorized(); // A forged webhook cannot activate an entitlement.
        $this->assertDatabaseCount('family_store_grants', 0);
        $this->postJson('/api/v1/app/productive-family/storefront/purchases/'.$first['id'].'/verify')
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $paid = true;
        $this->postJson('/api/v1/app/productive-family/storefront/purchases/'.$first['id'].'/verify')
            ->assertOk()->assertJsonPath('entitlement.plan', 'silver')->assertJsonPath('data.status', 'paid');
        $this->postJson('/api/v1/app/productive-family/storefront/purchases/'.$first['id'].'/verify')
            ->assertOk();
        $this->assertDatabaseCount('family_store_grants', 1);
        $receipt = $this->getJson('/api/v1/app/productive-family/storefront/purchases/'.$first['id'].'/receipt')
            ->assertOk()->json();
        $this->assertStringStartsWith('%PDF-', base64_decode($receipt['pdf_base64']));
        $this->assertSame(hash('sha256', base64_decode($receipt['pdf_base64'])), $receipt['sha256']);
        $this->assertDatabaseHas('family_store_purchases', [
            'id' => $first['id'], 'status' => 'paid', 'provider_payment_id' => $first['given_id'],
        ]);
        $paidPurchase = FamilyStorePurchase::query()->findOrFail($first['id']);
        $grant = FamilyStoreGrant::query()->findOrFail($paidPurchase->grant_id);
        $this->assertSame(30, (int) round($grant->starts_at->diffInDays($grant->ends_at)));
        $otherFamily = ProductiveFamily::query()->create([
            'code' => 'PF-TEST02', 'owner_name' => 'أسرة أخرى',
            'phone' => '0551234568', 'status' => 'approved',
        ]);
        $otherUser = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $otherUser->id, 'productive_family_id' => $otherFamily->id,
            'roles' => ['productive_family'], 'active_mode' => 'productive_family',
        ]);
        Sanctum::actingAs($otherUser);
        $this->getJson('/api/v1/app/productive-family/storefront/purchases/'.$first['id'].'/receipt')
            ->assertNotFound();
        Sanctum::actingAs($this->owner());
        $this->patchJson('/api/admin/family-store-receipt-settings', [
            'issuer_name' => 'زاد سينك', 'heading' => 'إيصال جديد',
            'intro' => 'رسالة جديدة', 'footer' => 'نص جديد',
            'accent_color' => '#9A3412', 'layout' => 'classic',
        ])->assertOk();
        $this->assertSame('فاتورة اشتراك زاد سينك', FamilyStorePurchase::query()->findOrFail($first['id'])->receipt_snapshot['heading']);
        $this->getJson('/api/admin/family-store-purchases/'.$first['id'].'/receipt')
            ->assertOk()->assertJsonPath('number', $receipt['number']);
        $this->travel(31)->days();
        $this->getJson('/api/v1/app/stores/'.Store::query()->firstOrFail()->id.'/products')
            ->assertOk()->assertJsonPath('store.plan', 'free');
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
            'zad_family_subscription_payment.enabled' => true,
            'moyasar.non_live_isolated' => true,
            'moyasar.test_publishable_key' => 'pk_test_fake',
            'moyasar.test_secret_key' => 'sk_test_fake',
        ]);
        PlatformControl::query()->create(['section' => 'family_subscriptions', 'value' => ['enabled' => true]]);
        PlatformControl::query()->create(['section' => 'services', 'value' => [
            'paymentGatewayEnabled' => true, 'paymentMode' => 'sandbox',
        ]]);
        Http::fake(function () {
            $purchase = FamilyStorePurchase::query()->latest('id')->firstOrFail();
            return Http::response([
                'id' => $purchase->given_id, 'status' => 'paid', 'amount' => 1,
                'currency' => 'SAR', 'live' => false,
                'metadata' => [
                    'family_store_purchase_id' => (string) $purchase->id,
                    'productive_family_id' => (string) $purchase->productive_family_id,
                    'environment' => 'sandbox',
                ],
            ]);
        });
        $purchase = $this->postJson('/api/v1/app/productive-family/storefront/purchases', ['plan' => 'silver'])
            ->assertCreated()->json('data');
        $this->postJson('/api/v1/app/productive-family/storefront/purchases/'.$purchase['id'].'/verify')
            ->assertStatus(422);
        $this->assertDatabaseCount('family_store_grants', 0);
        $this->assertSame('pending', FamilyStorePurchase::query()->findOrFail($purchase['id'])->status);
    }

    public function test_owner_price_change_is_reflected_exactly_in_new_checkout_and_existing_attempt_is_snapshotted(): void
    {
        [$family] = $this->family();
        $member = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $member->id, 'productive_family_id' => $family->id,
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
        Sanctum::actingAs($this->owner());
        $this->patchJson('/api/admin/family-store-plans/silver', ['price' => 45, 'duration_days' => 30])
            ->assertOk();
        Sanctum::actingAs($member);
        $purchase = $this->postJson('/api/v1/app/productive-family/storefront/purchases', ['plan' => 'silver'])
            ->assertCreated()->assertJsonPath('data.amount_halalas', 4500)
            ->assertJsonPath('data.payment_config.amount_halalas', 4500)->json('data');
        Sanctum::actingAs($this->owner());
        $this->patchJson('/api/admin/family-store-plans/silver', ['price' => 60])->assertOk();
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/app/productive-family/storefront/purchases/latest')
            ->assertOk()->assertJsonPath('data.amount_halalas', 4500)
            ->assertJsonPath('data.payment_config.amount_halalas', 4500)
            ->assertJsonPath('data.given_id', $purchase['given_id']);
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
