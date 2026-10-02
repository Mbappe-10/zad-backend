<?php

namespace Tests\Feature\Api;

use App\Models\AppProfile;
use App\Models\AppSetting;
use App\Models\Order;
use App\Models\PlatformControl;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\User;
use App\Services\App\GoogleIdentityVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class OwnerMasterSwitchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('internal_testing.enabled', false);
    }

    private function setControl(string $section, array $changes): void
    {
        $current = PlatformControl::query()
            ->where('section', $section)
            ->value('value') ?? [];

        PlatformControl::query()->updateOrCreate(
            ['section' => $section],
            ['value' => array_replace($current, $changes)],
        );

        PlatformControl::forgetCachedValues();
        Cache::forget('app.bootstrap.v1');
    }

    private function setPlatform(array $changes): void
    {
        $this->setControl('platform', $changes);
    }

    private function setLegacySetting(string $key, bool $enabled): void
    {
        AppSetting::query()
            ->where('key', $key)
            ->firstOrFail()
            ->update(['value' => $enabled]);
    }

    private function fakeGoogleIdentity(
        string $providerUserId,
        string $email,
    ): void {
        $this->mock(
            GoogleIdentityVerifier::class,
            function (MockInterface $mock) use (
                $providerUserId,
                $email,
            ): void {
                $mock->shouldReceive('verify')
                    ->once()
                    ->with('fake-id-token')
                    ->andReturn([
                        'sub' => $providerUserId,
                        'email' => $email,
                        'email_verified' => true,
                        'name' => 'Master Switch Test',
                    ]);
            },
        );
    }

    private function googleLogin(string $joinType)
    {
        return $this->postJson('/api/v1/app/auth/google', [
            'id_token' => 'fake-id-token',
            'join_type' => $joinType,
            'device_name' => 'owner-master-switches-test',
        ]);
    }

    private function enablePaymentMasters(): void
    {
        $this->setPlatform([
            'platformEnabled' => true,
            'maintenanceMode' => false,
        ]);
        $this->setControl('modules', ['financeEnabled' => true]);
        $this->setControl('services', ['paymentGatewayEnabled' => true]);
        $this->setControl('finance', ['paymentsEnabled' => true]);
    }

    private function guestOrder(): Order
    {
        $family = ProductiveFamily::query()->create([
            'code' => 'FAM-PAYMENT-TEST',
            'owner_name' => 'Payment Test Family',
            'phone' => '0500000001',
            'status' => 'active',
        ]);

        $store = Store::query()->create([
            'productive_family_id' => $family->id,
            'name_ar' => 'Payment Test Store',
            'slug' => 'payment-test-store',
            'status' => 'active',
            'is_open' => true,
        ]);

        return Order::query()->create([
            'number' => 'ZAD-PAYMENT-TEST-1',
            'store_id' => $store->id,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
            'subtotal' => 10,
            'delivery_fee' => 5,
            'discount' => 0,
            'tax' => 0,
            'total' => 15,
            'guest_session_id' =>
                '00000000-0000-4000-8000-000000000001',
            'contact_phone' => '0500000002',
        ]);
    }

    private function paymentUrl(Order $order, string $action): string
    {
        return "/api/v1/app/orders/{$order->id}/payment/{$action}";
    }

    private function guestHeaders(Order $order): array
    {
        return [
            'X-Guest-Session' => (string) $order->guest_session_id,
        ];
    }

    public function test_registrations_master_blocks_a_new_google_account(): void
    {
        $this->setPlatform([
            'platformEnabled' => true,
            'maintenanceMode' => false,
            'registrationsEnabled' => false,
        ]);
        $this->fakeGoogleIdentity(
            'google-new-blocked',
            'new-blocked@example.test',
        );

        $this->googleLogin('productive_family')
            ->assertForbidden();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('app_profiles', 0);
    }

    public function test_existing_google_account_can_log_in_while_registration_is_closed(): void
    {
        $email = 'existing-family@example.test';
        $user = User::factory()->create([
            'email' => $email,
            'auth_provider' => 'google',
            'provider_user_id' => 'google-existing-family',
            'status' => 'active',
            'is_approved' => true,
        ]);
        $family = ProductiveFamily::query()->create([
            'code' => 'FAM-EXISTING',
            'owner_name' => 'Existing Family',
            'phone' => '0500000003',
            'email' => $email,
            'status' => 'active',
        ]);
        Store::query()->create([
            'productive_family_id' => $family->id,
            'name_ar' => 'Existing Store',
            'slug' => 'existing-family-store',
            'status' => 'active',
            'is_open' => true,
        ]);
        AppProfile::query()->create([
            'user_id' => $user->id,
            'productive_family_id' => $family->id,
            'roles' => ['productive_family'],
            'active_mode' => 'productive_family',
        ]);

        $this->setPlatform([
            'platformEnabled' => false,
            'maintenanceMode' => true,
            'registrationsEnabled' => false,
        ]);
        $this->setControl(
            'productive_families',
            ['registrationsEnabled' => false],
        );
        $this->setLegacySetting('registration.family_enabled', false);
        $this->fakeGoogleIdentity(
            'google-existing-family',
            $email,
        );

        $this->googleLogin('productive_family')
            ->assertOk()
            ->assertJsonPath('is_new_user', false)
            ->assertJsonPath('account_linked', true)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('next_step', 'dashboard');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_productive_family_master_blocks_new_registration(): void
    {
        $this->setPlatform([
            'platformEnabled' => true,
            'maintenanceMode' => false,
            'registrationsEnabled' => true,
        ]);
        $this->setControl(
            'productive_families',
            ['registrationsEnabled' => false],
        );
        $this->setLegacySetting('registration.family_enabled', true);
        $this->fakeGoogleIdentity(
            'google-family-master-off',
            'family-master-off@example.test',
        );

        $this->googleLogin('productive_family')
            ->assertForbidden();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('productive_families', 0);
    }

    public function test_legacy_family_flag_still_blocks_new_registration(): void
    {
        $this->setPlatform([
            'platformEnabled' => true,
            'maintenanceMode' => false,
            'registrationsEnabled' => true,
        ]);
        $this->setControl(
            'productive_families',
            ['registrationsEnabled' => true],
        );
        $this->setLegacySetting('registration.family_enabled', false);
        $this->fakeGoogleIdentity(
            'google-family-legacy-off',
            'family-legacy-off@example.test',
        );

        $this->googleLogin('productive_family')
            ->assertForbidden();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('productive_families', 0);
    }

    public function test_legacy_driver_flag_still_blocks_new_registration(): void
    {
        $this->setPlatform([
            'platformEnabled' => true,
            'maintenanceMode' => false,
            'registrationsEnabled' => true,
        ]);
        $this->setLegacySetting('registration.driver_enabled', false);
        $this->fakeGoogleIdentity(
            'google-driver-legacy-off',
            'driver-legacy-off@example.test',
        );

        $this->googleLogin('driver')
            ->assertForbidden();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('drivers', 0);
    }

    public function test_existing_users_cannot_bypass_role_registration_gates(): void
    {
        $familyUser = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $familyUser->id,
            'roles' => ['productive_family'],
            'active_mode' => 'productive_family',
        ]);
        $this->setPlatform([
            'platformEnabled' => true,
            'maintenanceMode' => false,
            'registrationsEnabled' => true,
        ]);
        $this->setControl(
            'productive_families',
            ['registrationsEnabled' => false],
        );
        $this->setLegacySetting('registration.family_enabled', true);

        Sanctum::actingAs($familyUser);
        $this->postJson('/api/v1/app/productive-family/profile', [])
            ->assertForbidden();

        $driverUser = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $driverUser->id,
            'roles' => ['driver'],
            'active_mode' => 'driver',
        ]);
        $this->setControl(
            'productive_families',
            ['registrationsEnabled' => true],
        );
        $this->setLegacySetting('registration.driver_enabled', false);

        Sanctum::actingAs($driverUser);
        $this->postJson('/api/v1/app/driver/profile', [])
            ->assertForbidden();

        $this->assertDatabaseCount('productive_families', 0);
        $this->assertDatabaseCount('stores', 0);
        $this->assertDatabaseCount('drivers', 0);
    }

    public function test_new_orders_master_switch_blocks_before_order_validation(): void
    {
        $this->setPlatform([
            'platformEnabled' => true,
            'maintenanceMode' => false,
            'newOrdersEnabled' => false,
        ]);

        $this->postJson('/api/v1/app/orders', [])
            ->assertStatus(423);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_new_orders_master_switch_allows_request_to_reach_validation(): void
    {
        $this->setPlatform([
            'platformEnabled' => true,
            'maintenanceMode' => false,
            'newOrdersEnabled' => true,
        ]);

        $this->postJson('/api/v1/app/orders', [])
            ->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_platform_pause_and_maintenance_block_new_operations(): void
    {
        $blockedStates = [
            [
                'platformEnabled' => false,
                'maintenanceMode' => false,
            ],
            [
                'platformEnabled' => true,
                'maintenanceMode' => true,
            ],
        ];

        foreach ($blockedStates as $state) {
            $this->setPlatform($state + ['newOrdersEnabled' => true]);

            $this->postJson('/api/v1/app/orders', [])
                ->assertStatus(503);
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_each_payment_master_blocks_a_new_attempt_without_network(): void
    {
        $order = $this->guestOrder();
        config()->set('moyasar.publishable_key', 'pk_test_master_switch');
        Http::preventStrayRequests();

        $switches = [
            ['modules', 'financeEnabled'],
            ['services', 'paymentGatewayEnabled'],
            ['finance', 'paymentsEnabled'],
        ];

        foreach ($switches as [$section, $key]) {
            $this->enablePaymentMasters();
            $this->setControl($section, [$key => false]);

            $this->withHeaders($this->guestHeaders($order))
                ->postJson($this->paymentUrl($order, 'attempt'))
                ->assertStatus(423);
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('zad_payment_attempts', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(
            Order::PAYMENT_UNPAID,
            $order->fresh()->payment_status,
        );
    }

    public function test_enabled_payment_masters_allow_attempt_without_server_network(): void
    {
        $order = $this->guestOrder();
        $this->enablePaymentMasters();
        config()->set('moyasar.publishable_key', 'pk_test_master_switch');
        config()->set('moyasar.local_test_enabled', false);
        Http::preventStrayRequests();

        $this->withHeaders($this->guestHeaders($order))
            ->postJson($this->paymentUrl($order, 'attempt'))
            ->assertOk()
            ->assertJsonPath('data.paid', false)
            ->assertJsonPath(
                'data.publishable_key',
                'pk_test_master_switch',
            );

        Http::assertNothingSent();
        $this->assertDatabaseCount('zad_payment_attempts', 1);
        $this->assertSame(
            Order::PAYMENT_PENDING,
            $order->fresh()->payment_status,
        );
    }

    public function test_local_test_payment_respects_payment_masters(): void
    {
        $order = $this->guestOrder();
        $this->enablePaymentMasters();
        $this->setControl('finance', ['paymentsEnabled' => false]);
        config()->set('moyasar.local_test_enabled', true);
        Http::preventStrayRequests();

        $this->withHeaders($this->guestHeaders($order))
            ->postJson($this->paymentUrl($order, 'local-test'))
            ->assertStatus(423);

        Http::assertNothingSent();
        $this->assertDatabaseCount('zad_payment_attempts', 0);
        $this->assertDatabaseCount('payment_providers', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(
            Order::PAYMENT_UNPAID,
            $order->fresh()->payment_status,
        );
    }

    public function test_payment_status_and_reconciliation_remain_available(): void
    {
        $order = $this->guestOrder();
        $this->setPlatform([
            'platformEnabled' => false,
            'maintenanceMode' => true,
        ]);
        $this->setControl('modules', ['financeEnabled' => false]);
        $this->setControl('services', ['paymentGatewayEnabled' => false]);
        $this->setControl('finance', ['paymentsEnabled' => false]);

        config()->set('moyasar.secret_key', 'test-secret-key');
        config()->set(
            'moyasar.api_url',
            'https://api.moyasar.test/v1',
        );
        Http::fake([
            'https://api.moyasar.test/v1/payments/pay_reconcile_1' =>
                Http::response([
                    'id' => 'pay_reconcile_1',
                    'status' => 'paid',
                    'amount' => 1500,
                    'currency' => 'SAR',
                    'metadata' => [
                        'order_id' => (string) $order->id,
                    ],
                    'source' => ['type' => 'creditcard'],
                ]),
        ]);

        $this->withHeaders($this->guestHeaders($order))
            ->getJson($this->paymentUrl($order, 'status'))
            ->assertOk()
            ->assertJsonPath('data.paid', false);
        Http::assertNothingSent();

        $this->withHeaders($this->guestHeaders($order))
            ->postJson(
                $this->paymentUrl($order, 'verify'),
                ['payment_id' => 'pay_reconcile_1'],
            )
            ->assertOk()
            ->assertJsonPath('data.paid', true);

        Http::assertSentCount(1);
        $this->assertSame(
            Order::PAYMENT_PAID,
            $order->fresh()->payment_status,
        );
    }

    public function test_moyasar_webhook_ignores_runtime_master_switches(): void
    {
        $order = $this->guestOrder();
        $this->setPlatform([
            'platformEnabled' => false,
            'maintenanceMode' => true,
        ]);
        $this->setControl('modules', ['financeEnabled' => false]);
        $this->setControl('services', ['paymentGatewayEnabled' => false]);
        $this->setControl('finance', ['paymentsEnabled' => false]);
        config()->set('moyasar.webhook_secret', 'test-webhook-secret');
        Http::preventStrayRequests();

        $this->postJson('/api/v1/payments/moyasar/webhook', [
            'secret_token' => 'test-webhook-secret',
            'type' => 'payment_paid',
            'data' => [
                'id' => 'pay_webhook_1',
                'status' => 'paid',
                'amount' => 1500,
                'currency' => 'SAR',
                'metadata' => [
                    'order_id' => (string) $order->id,
                ],
                'source' => ['type' => 'creditcard'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('received', true);

        Http::assertNothingSent();
        $this->assertSame(
            Order::PAYMENT_PAID,
            $order->fresh()->payment_status,
        );
        $this->assertDatabaseHas('zad_payment_attempts', [
            'provider_payment_id' => 'pay_webhook_1',
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('payments', [
            'provider_reference' => 'pay_webhook_1',
            'status' => 'paid',
        ]);
    }

    public function test_existing_paid_order_remains_readable_during_platform_pause(): void
    {
        $order = $this->guestOrder();
        $order->update(['payment_status' => Order::PAYMENT_PAID]);
        $this->setPlatform([
            'platformEnabled' => false,
            'maintenanceMode' => true,
        ]);

        $this->withHeaders($this->guestHeaders($order))
            ->getJson("/api/v1/app/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id);
    }

    public function test_owner_control_center_stays_available_and_forgets_targeted_caches(): void
    {
        $this->setPlatform([
            'platformEnabled' => false,
            'maintenanceMode' => true,
        ]);
        $owner = User::factory()->create([
            'is_platform_owner' => true,
        ]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/admin/control-center')
            ->assertOk();

        Cache::put(PlatformControl::CACHE_KEY, ['stale' => true]);
        Cache::put('app.bootstrap.v1', ['stale' => true]);

        $this->patchJson('/api/admin/control-center/services', [
            'value' => ['paymentGatewayEnabled' => true],
            'reason' => 'Cache invalidation test',
        ])->assertOk();

        $this->assertFalse(Cache::has(PlatformControl::CACHE_KEY));
        $this->assertFalse(Cache::has('app.bootstrap.v1'));

        Cache::put(PlatformControl::CACHE_KEY, ['stale' => true]);
        Cache::put('app.bootstrap.v1', ['stale' => true]);

        $this->postJson('/api/admin/control-center/actions/orders-off', [
            'confirmation' => true,
            'reason' => 'Cache invalidation action test',
        ])->assertOk();

        $this->assertFalse(Cache::has(PlatformControl::CACHE_KEY));
        $this->assertFalse(Cache::has('app.bootstrap.v1'));
    }
}
