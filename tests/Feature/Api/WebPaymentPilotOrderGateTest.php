<?php

namespace Tests\Feature\Api;

use App\Models\AppGuestSession;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PlatformControl;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Services\App\GuestSessionAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** All payment-provider calls are intercepted; this suite NEVER charges. */
class WebPaymentPilotOrderGateTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'production');
        config()->set([
            'app.env' => 'production',
            'moyasar.live_publishable_key' => 'pk_live_fake_for_unit_test',
            'moyasar.live_secret_key' => 'sk_live_fake_for_unit_test',
            'zad_web_payment.enabled' => true,
            'zad_web_payment.pilot_enabled' => true,
            'zad_web_payment.pilot_order_id' => 0,
            'zad_web_payment.pilot_max_halalas' => 2000,
            'zad_web_payment.callback_url' => 'https://zad-sync-sa.onrender.com/zad-moyasar-return.html',
            'internal_testing.enabled' => false,
        ]);
        foreach ([
            'platform' => ['platformEnabled' => true, 'maintenanceMode' => false],
            'modules' => ['financeEnabled' => true],
            'services' => ['paymentGatewayEnabled' => true, 'paymentMode' => 'live'],
            'finance' => ['paymentsEnabled' => true],
        ] as $section => $changes) {
            $existing = PlatformControl::query()->where('section', $section)->value('value') ?? [];
            PlatformControl::query()->updateOrCreate(
                ['section' => $section],
                ['value' => array_replace($existing, $changes)],
            );
        }
        PlatformControl::forgetCachedValues();
        Http::preventStrayRequests();
    }

    private function order(): Order
    {
        $family = ProductiveFamily::query()->create([
            'code' => 'FAM-WEB-PILOT-TEST', 'owner_name' => 'Payment Pilot Family',
            'phone' => '0500000011', 'status' => 'active',
        ]);
        $store = Store::query()->create([
            'productive_family_id' => $family->id, 'name_ar' => 'Payment Pilot Store',
            'slug' => 'web-payment-pilot', 'status' => 'active', 'is_open' => true,
        ]);
        $guest = AppGuestSession::query()->create([
            'device_id' => 'zad-web-pilot-test-device', 'last_seen_at' => now(),
        ]);
        [$token] = app(GuestSessionAuthenticator::class)->issue($guest);
        $this->headers = [
            'X-Guest-Session' => (string) $guest->id,
            GuestSessionAuthenticator::HEADER => $token,
            'X-Device-ID' => 'zad-web-pilot-test-device',
        ];
        return Order::query()->create([
            'number' => 'ZAD-WEB-PILOT-TEST-1', 'store_id' => $store->id,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
            'subtotal' => 10, 'delivery_fee' => 5, 'discount' => 0, 'tax' => 0,
            'total' => 15, 'guest_session_id' => $guest->id,
            'contact_phone' => '0500000012',
            'checkout_request_id' => 'local-web-pilot-'.Str::uuid(),
        ]);
    }

    private function url(Order $order, string $action): string
    {
        return "/api/v1/app/orders/{$order->id}/payment/{$action}";
    }

    private function prepareAttempt(Order $order): string
    {
        $response = $this->withHeaders($this->headers)->postJson($this->url($order, 'attempt'));
        $response->assertOk();
        return (string) $response->json('data.given_id');
    }

    public function test_global_switch_off_blocks_authorized_order_without_provider_io(): void
    {
        $order = $this->order();
        $id = $this->prepareAttempt($order);
        config()->set('zad_web_payment.pilot_order_id', $order->id);
        config()->set('zad_web_payment.enabled', false);
        $this->withHeaders($this->headers)->postJson($this->url($order, 'web-intent'), ['given_id' => $id])
            ->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_missing_pilot_order_fails_closed_before_provider_io(): void
    {
        $order = $this->order();
        $id = $this->prepareAttempt($order);
        $this->withHeaders($this->headers)->postJson($this->url($order, 'web-intent'), ['given_id' => $id])
            ->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_other_order_id_is_rejected_before_provider_io(): void
    {
        $order = $this->order();
        $id = $this->prepareAttempt($order);
        config()->set('zad_web_payment.pilot_order_id', $order->id + 500);
        $this->withHeaders($this->headers)->postJson($this->url($order, 'web-intent'), ['given_id' => $id])
            ->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_pilot_amount_ceiling_blocks_request_before_provider_io(): void
    {
        $order = $this->order();
        $id = $this->prepareAttempt($order);
        config()->set('zad_web_payment.pilot_order_id', $order->id);
        config()->set('zad_web_payment.pilot_max_halalas', 1000);
        $this->withHeaders($this->headers)->postJson($this->url($order, 'web-intent'), ['given_id' => $id])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_only_the_selected_pilot_order_creates_intent_and_reuses_attempt(): void
    {
        $order = $this->order();
        $id = $this->prepareAttempt($order);
        config()->set('zad_web_payment.pilot_order_id', $order->id);
        $fixture = [
            'id' => (string) Str::uuid(), 'status' => 'created', 'amount' => 1500,
            'currency' => 'SAR', 'description' => 'ZAD Sync order '.$order->number,
            'callback_url' => 'https://zad-sync-sa.onrender.com/zad-moyasar-return.html',
            'client_secret' => 'pi_secret_PILOT_LOCAL_FAKE',
            'expires_at' => now()->addMinutes(45)->toIso8601String(),
        ];
        Http::fake(['https://api.moyasar.com/v1/payment_intents' => Http::response($fixture, 201)]);
        $this->withHeaders($this->headers)->postJson($this->url($order, 'web-intent'), ['given_id' => $id])
            ->assertOk()->assertJsonPath('data.given_id', $id);
        $this->assertDatabaseCount('zad_payment_attempts', 1);
        $this->assertSame('ready', PaymentAttempt::query()->firstOrFail()->payload['web_intent_state']);
        Http::assertSentCount(1);
    }

    public function test_unsafe_pilot_ceiling_configuration_fails_closed(): void
    {
        $order = $this->order();
        $id = $this->prepareAttempt($order);
        config()->set('zad_web_payment.pilot_order_id', $order->id);
        config()->set('zad_web_payment.pilot_max_halalas', 200000);
        $this->withHeaders($this->headers)
            ->postJson($this->url($order, 'web-intent'), ['given_id' => $id])
            ->assertStatus(422);
        Http::assertNothingSent();
    }
}
