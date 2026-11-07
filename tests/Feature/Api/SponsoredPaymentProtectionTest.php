<?php

namespace Tests\Feature\Api;

use App\Models\AppGuestSession;
use App\Models\Order;
use App\Models\PlatformControl;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\User;
use App\Services\App\GuestSessionAuthenticator;
use App\Services\SponsoredCreatorCouponService;
use App\Services\SponsoredCouponRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SponsoredPaymentProtectionTest extends TestCase
{
    use RefreshDatabase;

    private array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDefaultConnection() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('In-memory SQLite required.');
        }

        $this->app->detectEnvironment(fn () => 'production');

        config()->set([
            'app.env' => 'production',
            'moyasar.live_publishable_key' => 'pk_live_FAKE_TEST_ONLY',
            'moyasar.live_secret_key' => 'sk_live_FAKE_TEST_ONLY',
            'zad_web_payment.enabled' => true,
            'zad_web_payment.pilot_enabled' => false,
            'internal_testing.enabled' => false,
        ]);

        foreach ([
            'platform' => ['platformEnabled' => true, 'maintenanceMode' => false],
            'modules' => ['financeEnabled' => true],
            'services' => ['paymentGatewayEnabled' => true, 'paymentMode' => 'live'],
            'finance' => ['paymentsEnabled' => true],
        ] as $section => $changes) {
            $existing = PlatformControl::query()
                ->where('section', $section)->value('value') ?? [];

            PlatformControl::query()->updateOrCreate(
                ['section' => $section],
                ['value' => array_replace($existing, $changes)]
            );
        }

        PlatformControl::forgetCachedValues();
        Http::preventStrayRequests();
    }

    private function sponsoredOrder(): Order
    {
        $tag = bin2hex(random_bytes(6));

        $owner = User::factory()->create();
        $owner->is_platform_owner = true;
        $owner->save();

        $family = ProductiveFamily::query()->create([
            'code' => 'SPP-' . $tag,
            'owner_name' => 'Test Family',
            'phone' => '0500000011',
            'status' => 'active',
        ]);

        $store = Store::query()->create([
            'productive_family_id' => $family->id,
            'name_ar' => 'Sponsored Test Store',
            'slug' => 'sponsored-payment-' . $tag,
            'status' => 'active',
            'is_open' => true,
        ]);

        $creatorId = DB::table('launch_creators')->insertGetId([
            'code' => 'SPC-' . $tag,
            'name' => 'Test Creator',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('products')->insertGetId([
            'store_id' => $store->id,
            'name_ar' => 'Test Product',
            'price' => 20,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $guest = AppGuestSession::query()->create([
            'device_id' => 'sponsored-payment-test-device',
            'last_seen_at' => now(),
        ]);

        [$token] = app(GuestSessionAuthenticator::class)->issue($guest);

        $this->headers = [
            'X-Guest-Session' => (string) $guest->id,
            GuestSessionAuthenticator::HEADER => $token,
            'X-Device-ID' => 'sponsored-payment-test-device',
        ];

        $order = Order::query()->create([
            'number' => 'SPPAY-' . strtoupper($tag),
            'store_id' => $store->id,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
            'subtotal' => 20,
            'delivery_fee' => 5,
            'discount' => 0,
            'tax' => 0,
            'total' => 25,
            'guest_session_id' => $guest->id,
            'contact_phone' => '0500000012',
            'checkout_request_id' => 'sponsored-test-' . Str::uuid(),
        ]);

        $order->items()->create([
            'product_id' => $productId,
            'product_name' => 'Test Product',
            'quantity' => 1,
            'unit_price' => 20,
            'total' => 20,
        ]);

        $service = app(SponsoredCreatorCouponService::class);

        $created = $service->createDraft($owner, [
            'creator_id' => $creatorId,
            'store_id' => $store->id,
            'allowed_products' => [
                ['product_id' => $productId, 'max_quantity' => 1],
            ],
            'gross_cap' => 60,
        ]);

        $service->activate($owner, $created['coupon']->id, 15);

        DB::transaction(function () use ($created, $order, $productId) {
            app(SponsoredCouponRedemptionService::class)->redeem(
                $created['one_time_plain_code'],
                $order,
                [['product_id' => $productId, 'quantity' => 1]],
                2000,
                500,
                0,
                'sponsored-payment-protection-' . $order->id
            );
        });

        $this->assertTrue($order->fresh()->isSponsoredFunded());

        return $order->fresh();
    }

    public function test_sponsored_order_cannot_apply_another_coupon(): void
    {
        $order = $this->sponsoredOrder();

        $before = $order->fresh();
        $fundingBefore = DB::table('sponsored_order_fundings')
            ->where('order_id', $order->id)
            ->first();

        $this->assertNotNull($fundingBefore);

        $this->withHeaders($this->headers)
            ->postJson(
                "/api/v1/app/orders/{$order->id}/coupon/apply",
                ['coupon_code' => 'FLOW20']
            )
            ->assertStatus(422);

        $after = $order->fresh();

        $this->assertEquals($before->total, $after->total);
        $this->assertEquals($before->discount, $after->discount);
        $this->assertTrue($after->isSponsoredFunded());

        $this->assertSame(
            1,
            DB::table('sponsored_order_fundings')
                ->where('order_id', $order->id)
                ->where('funding_source', 'zad_platform')
                ->where('status', 'committed')
                ->count()
        );

        $this->assertDatabaseCount('zad_payment_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_sponsored_order_cannot_remove_coupon(): void
    {
        $order = $this->sponsoredOrder();

        $before = $order->fresh();

        $this->withHeaders($this->headers)
            ->postJson("/api/v1/app/orders/{$order->id}/coupon/remove")
            ->assertStatus(422);

        $after = $order->fresh();

        $this->assertEquals($before->total, $after->total);
        $this->assertEquals($before->discount, $after->discount);
        $this->assertTrue($after->isSponsoredFunded());

        $this->assertSame(
            1,
            DB::table('sponsored_order_fundings')
                ->where('order_id', $order->id)
                ->where('funding_source', 'zad_platform')
                ->where('status', 'committed')
                ->count()
        );

        $this->assertDatabaseCount('zad_payment_attempts', 0);
        Http::assertNothingSent();
    }
    public function test_sponsored_order_cannot_create_payment_attempt(): void
    {
        $order = $this->sponsoredOrder();

        $this->withHeaders($this->headers)
            ->postJson("/api/v1/app/orders/{$order->id}/payment/attempt")
            ->assertStatus(409);

        $this->assertDatabaseCount('zad_payment_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_sponsored_order_cannot_create_web_payment_intent(): void
    {
        $order = $this->sponsoredOrder();

        $this->withHeaders($this->headers)
            ->postJson(
                "/api/v1/app/orders/{$order->id}/payment/web-intent",
                ['given_id' => (string) Str::uuid()]
            )
            ->assertStatus(409);

        $this->assertDatabaseCount('zad_payment_attempts', 0);
        Http::assertNothingSent();
    }
}