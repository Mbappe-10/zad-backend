<?php

namespace Tests\Feature\Api;

use App\Models\AppGuestSession;
use App\Models\Order;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\User;
use App\Services\App\GuestSessionAuthenticator;
use App\Services\SponsoredCreatorCouponService;
use App\Services\VehiclePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class SponsoredCheckoutHttpIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDefaultConnection() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('SQLite memory required.');
        }

        config()->set('zad_routing.enabled', false);

        $pricing = Mockery::mock(VehiclePricingService::class);
        $pricing->shouldReceive('quote')
            ->andReturn(5.0);

        $this->app->instance(VehiclePricingService::class, $pricing);
    }

    private function fixture(): array
    {
        $tag = bin2hex(random_bytes(6));

        $owner = User::factory()->create();
        $owner->is_platform_owner = true;
        $owner->save();

        $family = ProductiveFamily::query()->create([
            'code' => 'SCH-' . $tag,
            'owner_name' => 'HTTP Family',
            'phone' => '0500000011',
            'status' => 'active',
        ]);

        $store = Store::query()->create([
            'productive_family_id' => $family->id,
            'name_ar' => 'HTTP Sponsored Store',
            'slug' => 'sch-' . $tag,
            'status' => 'active',
            'is_open' => true,
            'pickup_address' => 'Test pickup',
            'pickup_latitude' => 21.4225,
            'pickup_longitude' => 39.8262,
        ]);

        $productId = DB::table('products')->insertGetId([
            'store_id' => $store->id,
            'name_ar' => 'Sponsored Product',
            'price' => 20,
            'status' => 'active',
            'is_available' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $creatorId = DB::table('launch_creators')->insertGetId([
            'code' => 'SCH-C-' . $tag,
            'name' => 'HTTP Creator',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(SponsoredCreatorCouponService::class);

        $created = $service->createDraft($owner, [
            'creator_id' => $creatorId,
            'store_id' => $store->id,
            'allowed_products' => [
                ['product_id' => $productId, 'max_quantity' => 2],
            ],
            'gross_cap' => 60,
            'display_name' => 'Sponsored Checkout Test',
            'coupon_code' => 'ZAD-CHECKOUT-' . strtoupper($tag),
        ]);

        $service->activate($owner, $created['coupon']->id, 15);

        $guest = AppGuestSession::query()->create([
            'device_id' => 'sponsored-http-device',
            'last_seen_at' => now(),
        ]);

        [$token] = app(GuestSessionAuthenticator::class)->issue($guest);

        return [
            'coupon_id' => $created['coupon']->id,
            'headers' => [
                'X-Guest-Session' => (string) $guest->id,
                GuestSessionAuthenticator::HEADER => $token,
                'X-Device-ID' => 'sponsored-http-device',
            ],
            'payload' => [
                'sponsored_coupon_code' => $created['one_time_plain_code'],
                'guest_session_id' => (string) $guest->id,
                'device_id' => 'sponsored-http-device',
                'checkout_request_id' => (string) Str::uuid(),
                'contact_phone' => '0500000011',
                'store_id' => $store->id,
                'distance_km' => 0,
                'latitude' => 21.43,
                'longitude' => 39.83,
                'address' => [
                    'label' => 'HTTP Test Address',
                    'description' => 'Makkah test delivery',
                ],
                'items' => [
                    ['product_id' => $productId, 'quantity' => 1],
                ],
            ],
        ];
    }

    public function test_sponsored_checkout_retry_and_tracking(): void
    {
        $f = $this->fixture();

        $first = $this->withHeaders($f['headers'])
            ->postJson('/api/v1/app/orders', $f['payload'])
            ->assertCreated()
            ->assertJsonPath('sponsored_funded', true)
            ->assertJsonPath('amount_due', 0);

        $orderId = $first->json('data.id');

        $this->assertGreaterThan(0, (int) $orderId);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'payment_status' => Order::PAYMENT_UNPAID,
        ]);

        $this->assertDatabaseHas('sponsored_order_fundings', [
            'order_id' => $orderId,
            'funding_source' => 'zad_platform',
            'status' => 'committed',
        ]);

        $this->assertDatabaseHas('sponsored_creator_coupons', [
            'id' => $f['coupon_id'],
            'status' => 'consumed',
            'consumed_order_id' => $orderId,
        ]);

        $retry = $this->withHeaders($f['headers'])
            ->postJson('/api/v1/app/orders', $f['payload'])
            ->assertCreated();

        $this->assertEquals($orderId, $retry->json('data.id'));
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('sponsored_order_fundings')->count());

        $this->withHeaders($f['headers'])
            ->getJson("/api/v1/app/orders/{$orderId}")
            ->assertOk();

        $list = $this->withHeaders($f['headers'])
            ->getJson('/api/v1/app/orders')
            ->assertOk();

        $listed = collect($list->json('data'))
            ->firstWhere('id', $orderId);

        $this->assertNotNull($listed);
        $this->assertTrue($listed['sponsored_funded']);
        // ZAD_SPONSORED_RECOVERY_CONTRACT_V1
        $this->assertArrayHasKey('checkout_request_id', $listed);
        $this->assertSame(
            $f['payload']['checkout_request_id'],
            $listed['checkout_request_id']
        );

        $secondPayload = $f['payload'];
        $secondPayload['checkout_request_id'] = (string) Str::uuid();

        $this->withHeaders($f['headers'])
            ->postJson('/api/v1/app/orders', $secondPayload)
            ->assertUnprocessable();

        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('sponsored_order_fundings')->count());
    }
}