<?php

namespace Tests\Feature\Api;

use App\Models\AppGuestSession;
use App\Models\Order;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Services\App\GuestSessionAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SponsoredOrderHttpVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDefaultConnection() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('SQLite in-memory required.');
        }
    }

    private function fixture(bool $funded): array
    {
        $tag = bin2hex(random_bytes(6));

        $family = ProductiveFamily::query()->create([
            'code' => 'SHV-' . $tag,
            'owner_name' => 'HTTP Test Family',
            'phone' => '0500000011',
            'status' => 'active',
        ]);

        $store = Store::query()->create([
            'productive_family_id' => $family->id,
            'name_ar' => 'HTTP Test Store',
            'slug' => 'shv-' . $tag,
            'status' => 'active',
            'is_open' => true,
        ]);

        $guest = AppGuestSession::query()->create([
            'device_id' => 'sponsored-http-device',
            'last_seen_at' => now(),
        ]);

        [$token] = app(GuestSessionAuthenticator::class)->issue($guest);

        $order = Order::query()->create([
            'number' => 'SHV-' . strtoupper($tag),
            'store_id' => $store->id,
            'guest_session_id' => $guest->id,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
            'subtotal' => 20,
            'delivery_fee' => 5,
            'discount' => 0,
            'tax' => 0,
            'total' => 25,
        ]);

        if ($funded) {
            $owner = \App\Models\User::factory()->create();
            $owner->is_platform_owner = true;
            $owner->save();

            $creatorId = DB::table('launch_creators')->insertGetId([
                'code' => 'SHV-C-' . $tag,
                'name' => 'HTTP Creator',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $couponId = DB::table('sponsored_creator_coupons')->insertGetId([
                'created_by' => $owner->id,
                'creator_id' => $creatorId,
                'store_id' => $store->id,
                'code_hash' => hash('sha256', 'SHV-' . $tag),
                'status' => 'consumed',
                'allowed_products' => '[]',
                'gross_cap' => 60,
                'activated_at' => now(),
                'expires_at' => now()->addMinutes(15),
                'consumed_order_id' => $order->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('sponsored_order_fundings')->insert([
                'coupon_id' => $couponId,
                'order_id' => $order->id,
                'idempotency_key' => 'shv-' . $tag,
                'funding_source' => 'zad_platform',
                'products_amount' => 20,
                'delivery_amount' => 5,
                'tax_amount' => 0,
                'funded_total' => 25,
                'status' => 'committed',
                'committed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$order, [
            'X-Guest-Session' => (string) $guest->id,
            GuestSessionAuthenticator::HEADER => $token,
            'X-Device-ID' => 'sponsored-http-device',
        ]];
    }

    public function test_funded_order_is_visible_and_trackable(): void
    {
        [$order, $headers] = $this->fixture(true);

        $list = $this->withHeaders($headers)
            ->getJson('/api/v1/app/orders')
            ->assertOk();

        $listed = collect($list->json('data'))
            ->firstWhere('id', $order->id);

        $this->assertNotNull($listed);
        $this->assertTrue($listed['sponsored_funded']);

        $this->withHeaders($headers)
            ->getJson("/api/v1/app/orders/{$order->id}")
            ->assertOk();
    }

    public function test_unpaid_unfunded_order_cannot_be_tracked(): void
    {
        [$order, $headers] = $this->fixture(false);

        $list = $this->withHeaders($headers)
            ->getJson('/api/v1/app/orders')
            ->assertOk();

        $listed = collect($list->json('data'))
            ->firstWhere('id', $order->id);

        $this->assertNotNull($listed);
        $this->assertFalse($listed['sponsored_funded']);

        $this->withHeaders($headers)
            ->getJson("/api/v1/app/orders/{$order->id}")
            ->assertStatus(402);
    }
}