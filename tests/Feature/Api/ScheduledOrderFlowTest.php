<?php

namespace Tests\Feature\Api;

use App\Models\AppGuestSession;
use App\Models\AppProfile;
use App\Models\Order;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\User;
use App\Services\App\GuestSessionAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ScheduledOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDefaultConnection() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('In-memory SQLite testing only.');
        }
    }

    public function test_owning_family_can_confirm_without_changing_order_status_and_customer_is_notified_once(): void
    {
        [$order, $owner, $guest] = $this->fixture();

        Sanctum::actingAs($owner);

        $endpoint = "/api/v1/app/family/orders/{$order->id}/schedule-decision";

        $this->postJson($endpoint, ['decision' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_PENDING)
            ->assertJsonPath('data.schedule.family_status', 'confirmed')
            ->assertJsonPath('data.schedule.decline_reason', null);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('order_schedules', [
            'order_id' => $order->id,
            'family_status' => 'confirmed',
            'decline_reason' => null,
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'guest_session_id' => $guest->id,
            'type' => 'transactional',
        ]);

        $this->postJson($endpoint, ['decision' => 'confirmed'])
            ->assertOk();

        $this->assertDatabaseCount('in_app_notifications', 1);
    }

    public function test_decline_requires_a_reason_and_only_the_owning_family_can_decide(): void
    {
        [$order, $owner] = $this->fixture();

        Sanctum::actingAs($owner);

        $this->postJson(
            "/api/v1/app/family/orders/{$order->id}/schedule-decision",
            ['decision' => 'declined'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $otherFamily = ProductiveFamily::query()->create([
            'code' => 'OTHER-'.bin2hex(random_bytes(4)),
            'owner_name' => 'Other Family',
            'phone' => '0500000022',
            'status' => 'active',
        ]);
        $otherUser = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $otherUser->id,
            'productive_family_id' => $otherFamily->id,
            'roles' => ['family'],
            'active_mode' => 'family',
        ]);

        Sanctum::actingAs($otherUser);

        $this->postJson(
            "/api/v1/app/family/orders/{$order->id}/schedule-decision",
            [
                'decision' => 'declined',
                'reason' => 'لا يمكن تنفيذ الطلب في هذا الموعد.',
            ],
        )->assertForbidden();

        $this->assertDatabaseHas('order_schedules', [
            'order_id' => $order->id,
            'family_status' => 'pending',
        ]);
    }

    public function test_pending_family_confirmation_blocks_preparing_and_ready_proof_for_older_clients(): void
    {
        [$order, $owner] = $this->fixture();

        Sanctum::actingAs($owner);

        $this->postJson(
            "/api/v1/app/family/orders/{$order->id}/transition",
            ['status' => Order::STATUS_PREPARING],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['schedule']);

        $this->postJson(
            "/api/v1/app/family/orders/{$order->id}/ready-proof",
            [],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['schedule']);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_PENDING,
        ]);
        $this->assertDatabaseMissing('order_journey_proofs', [
            'order_id' => $order->id,
            'stage' => 'family_ready',
        ]);
    }

    public function test_schedule_is_exposed_to_customer_family_and_admin_order_payloads(): void
    {
        [$order, $owner, $guest] = $this->fixture();

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/app/family/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.schedule.occasion', 'حفل تخرج')
            ->assertJsonPath('data.schedule.guest_count', 24)
            ->assertJsonPath('data.schedule.family_status', 'pending');

        $this->getJson("/api/core/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.schedule.occasion', 'حفل تخرج');

        auth()->forgetGuards();
        [$token] = app(GuestSessionAuthenticator::class)->issue($guest);
        $headers = [
            'X-Guest-Session' => (string) $guest->id,
            GuestSessionAuthenticator::HEADER => $token,
            'X-Device-ID' => 'scheduled-order-device',
        ];

        $this->withHeaders($headers)
            ->getJson('/api/v1/app/orders')
            ->assertOk()
            ->assertJsonPath('data.0.schedule.occasion', 'حفل تخرج');

        $this->withHeaders($headers)
            ->getJson("/api/v1/app/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.schedule.family_status', 'pending');
    }

    /** @return array{Order, User, AppGuestSession} */
    private function fixture(): array
    {
        $tag = bin2hex(random_bytes(5));
        $family = ProductiveFamily::query()->create([
            'code' => 'SCHEDULE-'.$tag,
            'owner_name' => 'Scheduled Family',
            'phone' => '0500000011',
            'status' => 'active',
        ]);
        $store = Store::query()->create([
            'productive_family_id' => $family->id,
            'name_ar' => 'Scheduled Store',
            'slug' => 'scheduled-'.$tag,
            'status' => 'active',
            'is_open' => true,
            'pickup_address' => 'Test pickup',
            'pickup_latitude' => 24.7136,
            'pickup_longitude' => 46.6753,
        ]);
        $owner = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $owner->id,
            'productive_family_id' => $family->id,
            'roles' => ['family'],
            'active_mode' => 'family',
        ]);
        $guest = AppGuestSession::query()->create([
            'device_id' => 'scheduled-order-device',
            'last_seen_at' => now(),
        ]);
        $order = Order::query()->create([
            'number' => 'SCHEDULE-'.strtoupper($tag),
            'store_id' => $store->id,
            'guest_session_id' => $guest->id,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_PAID,
            'contact_phone' => '0500000099',
            'contact_verification_state' => 'verified',
            'contact_verified_at' => now(),
            'subtotal' => 100,
            'delivery_fee' => 10,
            'discount' => 0,
            'tax' => 0,
            'total' => 110,
        ]);

        DB::table('order_schedules')->insert([
            'order_id' => $order->id,
            'delivery_at' => now()->addDays(3),
            'occasion' => 'حفل تخرج',
            'guest_count' => 24,
            'family_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$order, $owner, $guest];
    }
}
