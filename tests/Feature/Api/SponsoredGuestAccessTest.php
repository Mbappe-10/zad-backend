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

final class SponsoredGuestAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDefaultConnection() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('In-memory SQLite required.');
        }
    }

    private function fixture(): array
    {
        $tag = bin2hex(random_bytes(5));

        $family = ProductiveFamily::query()->create([
            'code' => 'SGA-' . $tag,
            'owner_name' => 'Guest Test Family',
            'phone' => '0500000011',
            'status' => 'active',
        ]);

        $store = Store::query()->create([
            'productive_family_id' => $family->id,
            'name_ar' => 'Guest Test Store',
            'slug' => 'guest-test-' . $tag,
            'status' => 'active',
            'is_open' => true,
        ]);

        $guest = AppGuestSession::query()->create([
            'device_id' => 'guest-access-test-device',
            'last_seen_at' => now(),
        ]);

        [$token] = app(GuestSessionAuthenticator::class)->issue($guest);

        Order::query()->create([
            'number' => 'SGA-' . strtoupper($tag),
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

        return [$guest, $token];
    }

    public function test_valid_guest_token_can_access_order_list(): void
    {
        [$guest, $token] = $this->fixture();

        $this->withHeaders([
            'X-Guest-Session' => (string) $guest->id,
            GuestSessionAuthenticator::HEADER => $token,
            'X-Device-ID' => 'guest-access-test-device',
        ])->getJson('/api/v1/app/orders')->assertOk();
    }

    public function test_guest_uuid_without_token_is_rejected(): void
    {
        [$guest] = $this->fixture();

        $this->withHeaders([
            'X-Guest-Session' => (string) $guest->id,
        ])->getJson('/api/v1/app/orders')->assertStatus(401);
    }

    public function test_invalid_guest_token_is_rejected(): void
    {
        [$guest] = $this->fixture();

        $this->withHeaders([
            'X-Guest-Session' => (string) $guest->id,
            GuestSessionAuthenticator::HEADER => 'invalid-test-token',
        ])->getJson('/api/v1/app/orders')->assertStatus(401);
    }
}