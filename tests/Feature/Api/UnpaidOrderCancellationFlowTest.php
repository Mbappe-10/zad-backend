<?php

namespace Tests\Feature\Api;

use App\Models\AppGuestSession;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Services\App\GuestSessionAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

// Load the worktree controller before Composer resolves the original copy.
require_once dirname(__DIR__, 3)
    . '/app/Http/Controllers/Api/App/UnpaidOrderCancellationController.php';
require_once dirname(__DIR__, 3)
    . '/app/Http/Controllers/Api/App/MoyasarPaymentController.php';

class UnpaidOrderCancellationFlowTest extends TestCase
{
    use RefreshDatabase;

    private array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'moyasar.live_secret_key' => 'sk_live_FAKE_TEST_ONLY',
            'moyasar.live_publishable_key' => 'pk_live_FAKE_TEST_ONLY',
        ]);

        $this->app->detectEnvironment(fn () => 'production');

        config()->set([
            'app.env' => 'production',
            'zad_web_payment.enabled' => true,
            'zad_web_payment.pilot_enabled' => false,
            'internal_testing.enabled' => false,
        ]);

        foreach ([
            'platform' => [
                'platformEnabled' => true,
                'maintenanceMode' => false,
            ],
            'modules' => [
                'financeEnabled' => true,
            ],
            'services' => [
                'paymentGatewayEnabled' => true,
                'paymentMode' => 'live',
            ],
            'finance' => [
                'paymentsEnabled' => true,
            ],
        ] as $section => $changes) {
            $existing = \App\Models\PlatformControl::query()
                ->where('section', $section)
                ->value('value') ?? [];

            \App\Models\PlatformControl::query()->updateOrCreate(
                ['section' => $section],
                ['value' => array_replace($existing, $changes)]
            );
        }

        \App\Models\PlatformControl::forgetCachedValues();

        Http::preventStrayRequests();
    }

    private function makeOrder(): Order
    {
        $family = ProductiveFamily::query()->create([
            'code' => 'FAM-CANCEL-TEST',
            'owner_name' => 'Cancellation Test',
            'phone' => '0500000011',
            'status' => 'active',
        ]);

        $store = Store::query()->create([
            'productive_family_id' => $family->id,
            'name_ar' => 'Cancellation Test Store',
            'slug' => 'cancel-test-store',
            'status' => 'active',
            'is_open' => true,
        ]);

        $guest = AppGuestSession::query()->create([
            'device_id' => 'cancel-test-device',
            'last_seen_at' => now(),
        ]);

        [$token] = app(GuestSessionAuthenticator::class)->issue($guest);

        $this->headers = [
            'X-Guest-Session' => (string) $guest->id,
            GuestSessionAuthenticator::HEADER => $token,
            'X-Device-ID' => 'cancel-test-device',
        ];

        return Order::query()->create([
            'number' => 'ZAD-CANCEL-TEST-1',
            'store_id' => $store->id,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
            'subtotal' => 10,
            'delivery_fee' => 5,
            'discount' => 0,
            'tax' => 0,
            'total' => 15,
            'guest_session_id' => $guest->id,
            'contact_phone' => '0500000012',
            'checkout_request_id' => 'cancel-test-'.Str::uuid(),
        ]);
    }

    private function url(Order $order): string
    {
        return "/api/v1/app/orders/{$order->id}/cancel-unpaid";
    }

    private function makeAttempt(Order $order, string $state, ?string $intentId = null): void
    {
        $attemptResponse = $this->withHeaders($this->headers)
            ->postJson("/api/v1/app/orders/{$order->id}/payment/attempt");

        $attemptResponse->assertOk();

        $attempt = PaymentAttempt::query()
            ->where('order_id', $order->id)
            ->firstOrFail();

        $payload = is_array($attempt->payload) ? $attempt->payload : [];

        $payload['web_intent_state'] = $state;

        if ($intentId !== null) {
            $payload['web_intent_id'] = $intentId;
        }

        $attempt->update(['payload' => $payload]);
    }

    public function test_ready_unpaid_intent_is_canceled(): void
    {
        $order = $this->makeOrder();
        $id = (string) Str::uuid();

        $this->makeAttempt($order, 'ready', $id);

        Http::fake([
            "https://api.moyasar.com/v1/payment_intents/{$id}" =>
                Http::response(['id' => $id, 'status' => 'created'], 200),
            "https://api.moyasar.com/v1/payment_intents/{$id}/cancel" =>
                Http::response(['id' => $id, 'status' => 'canceled'], 200),
        ]);

        $attempt = PaymentAttempt::query()
            ->where('order_id', $order->id)
            ->firstOrFail();

        fwrite(STDERR, "\n=== ATTEMPT DIAGNOSTIC ===\n");
        fwrite(STDERR, json_encode([
            'gateway' => $attempt->gateway,
            'status' => $attempt->status,
            'provider_payment_id' => $attempt->provider_payment_id,
            'web_intent_state' => $attempt->payload['web_intent_state'] ?? null,
            'web_intent_id' => $attempt->payload['web_intent_id'] ?? null,
            'order_status' => $order->fresh()->status,
            'payment_status' => $order->fresh()->payment_status,
        ], JSON_PRETTY_PRINT)."\n");

        $response = $this->withHeaders($this->headers)
            ->postJson($this->url($order));

        fwrite(STDERR, "\n=== CANCELLATION RESPONSE ===\n");
        fwrite(STDERR, 'HTTP '.$response->status()."\n");
        fwrite(STDERR, $response->getContent()."\n");

        $response->assertOk();

        $this->assertSame(
            Order::STATUS_CANCELLED,
            $order->fresh()->status
        );

        Http::assertSentCount(2);
    }

    public function test_fulfilled_intent_cannot_be_canceled(): void
    {
        $order = $this->makeOrder();
        $id = (string) Str::uuid();

        $this->makeAttempt($order, 'ready', $id);

        Http::fake([
            '*' => Http::response([
                'id' => $id,
                'status' => 'fulfilled',
            ], 200),
        ]);

        $this->withHeaders($this->headers)
            ->postJson($this->url($order))
            ->assertStatus(409);

        $this->assertSame(
            Order::STATUS_PENDING,
            $order->fresh()->status
        );

        Http::assertSentCount(1);
    }

    public function test_provider_failure_keeps_order_pending(): void
    {
        $order = $this->makeOrder();
        $id = (string) Str::uuid();

        $this->makeAttempt($order, 'ready', $id);

        Http::fake([
            '*' => Http::response(['message' => 'Unavailable'], 503),
        ]);

        $this->withHeaders($this->headers)
            ->postJson($this->url($order))
            ->assertStatus(503);

        $this->assertSame(
            Order::STATUS_PENDING,
            $order->fresh()->status
        );
    }

    public function test_creating_intent_cannot_be_canceled(): void
    {
        $order = $this->makeOrder();

        $this->makeAttempt($order, 'creating');

        Http::fake();

        $this->withHeaders($this->headers)
            ->postJson($this->url($order))
            ->assertStatus(409);

        $this->assertSame(
            Order::STATUS_PENDING,
            $order->fresh()->status
        );

        Http::assertNothingSent();
    }

    public function test_late_paid_webhook_cannot_reopen_cancelled_order(): void
    {
        $order = $this->makeOrder();
        $paymentId = (string) Str::uuid();

        $this->makeAttempt($order, 'ready', $paymentId);

        $attempt = PaymentAttempt::query()
            ->where('order_id', $order->id)
            ->firstOrFail();

        $attempt->update(['idempotency_key' => $paymentId]);

        Http::fake([
            "https://api.moyasar.com/v1/payment_intents/{$paymentId}" =>
                Http::response(['id' => $paymentId, 'status' => 'created']),
            "https://api.moyasar.com/v1/payment_intents/{$paymentId}/cancel" =>
                Http::response(['id' => $paymentId, 'status' => 'canceled']),
        ]);

        $this->withHeaders($this->headers)
            ->postJson($this->url($order))
            ->assertOk();

        $this->assertSame(
            Order::STATUS_CANCELLED,
            $order->fresh()->status
        );

        config()->set('moyasar.webhook_secret', 'test-webhook-secret');

        Http::fake([
            "https://api.moyasar.com/v1/payments/{$paymentId}" =>
                Http::response([
                    'id' => $paymentId,
                    'status' => 'paid',
                    'amount' => 1500,
                    'currency' => 'SAR',
                    'metadata' => [
                        'order_id' => (string) $order->id,
                        'order_number' => (string) $order->number,
                        'environment' => 'live',
                    ],
                    'source' => ['type' => 'creditcard'],
                ]),
        ]);

        $response = $this->postJson(
            '/api/v1/payments/moyasar/webhook',
            [
                'secret_token' => 'test-webhook-secret',
                'live' => true,
                'type' => 'payment_paid',
                'data' => [
                    'id' => $paymentId,
                    'metadata' => [
                        'order_id' => (string) $order->id,
                    ],
                ],
            ]
        );

        $this->assertContains($response->status(), [200, 409, 422]);

        $this->assertSame(
            Order::STATUS_CANCELLED,
            $order->fresh()->status
        );

        $this->assertNotSame(
            Order::PAYMENT_PAID,
            $order->fresh()->payment_status
        );

        $this->assertDatabaseMissing('payments', [
            'provider_reference' => $paymentId,
        ]);

        $this->assertDatabaseMissing('zad_payment_attempts', [
            'id' => $attempt->id,
            'status' => 'paid',
        ]);
    }
}