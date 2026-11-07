<?php

namespace Tests\Feature\Api;

use App\Models\AppGuestSession;
use App\Models\AppProfile;
use App\Models\Order;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderPreparationDecisionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SponsoredPreparationDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (app()->environment() !== 'testing'
            || DB::getDefaultConnection() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('In-memory SQLite testing only.');
        }

        config()->set('zad_v4_family_actions.enabled', true);
    }

    private function sponsoredFixture(): array
    {
        $tag = bin2hex(random_bytes(5));

        $family = ProductiveFamily::query()->create([
            'code' => 'SPD-' . $tag,
            'owner_name' => 'Test Family',
            'phone' => '0500000011',
            'status' => 'active',
        ]);

        $store = Store::query()->create([
            'productive_family_id' => $family->id,
            'name_ar' => 'Test Sponsored Store',
            'slug' => 'spd-' . $tag,
            'status' => 'active',
            'is_open' => true,
        ]);

        $user = User::factory()->create();

        AppProfile::query()->create([
            'user_id' => $user->id,
            'productive_family_id' => $family->id,
            'roles' => ['family'],
            'active_mode' => 'family',
        ]);

        $guest = AppGuestSession::query()->create([
            'device_id' => 'sponsored-preparation-device',
            'last_seen_at' => now(),
        ]);

        $order = Order::query()->create([
            'number' => 'SPD-' . strtoupper($tag),
            'store_id' => $store->id,
            'guest_session_id' => $guest->id,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
            'contact_verification_state' => 'pending',
            'subtotal' => 20,
            'delivery_fee' => 5,
            'discount' => 0,
            'tax' => 0,
            'total' => 25,
        ]);

        // This test inserts a committed funding record directly.
        // Coupon redemption validity is covered by separate tests.
        $couponOwner = User::factory()->create();

        $creatorId = DB::table('launch_creators')->insertGetId([
            'code' => 'SPD-C-' . $tag,
            'name' => 'Test Creator',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $couponId = DB::table('sponsored_creator_coupons')->insertGetId([
            'creator_id' => $creatorId,
            'store_id' => $store->id,
            'created_by' => $couponOwner->id,
            'code_hash' => hash('sha256', 'SPD-' . $tag),
            'allowed_products' => json_encode([]),
            'gross_cap' => 50,
            'status' => 'consumed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sponsored_order_fundings')->insert([
            'coupon_id' => $couponId,
            'order_id' => $order->id,
            'funding_source' => 'zad_platform',
            'currency' => 'SAR',
            'products_amount' => 20,
            'delivery_amount' => 5,
            'tax_amount' => 0,
            'funded_total' => 25,
            'status' => 'committed',
            'idempotency_key' => 'spd-' . $tag,
            'committed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($order->fresh()->isSponsoredFunded());


        return [$order, $guest, $user, $family];
    }

    private function requestExtension(Order $order, User $user): void
    {
        Sanctum::actingAs($user);

        $this->postJson(
            "/api/v1/app/family/orders/{$order->id}/preparation-decision",
            [
                'kind' => 'extension',
                'extra_minutes' => 5,
                'compensation_sar' => 3,
                'reason' => 'نحتاج وقتًا إضافيًا لتجهيز الطلب',
            ]
        )->assertCreated();
    }

    private function guestHeaders(AppGuestSession $guest): array
    {
        [$token] = app(
            \App\Services\App\GuestSessionAuthenticator::class
        )->issue($guest);

        return [
            'X-Guest-Session' => (string) $guest->id,
            \App\Services\App\GuestSessionAuthenticator::HEADER => $token,
            'X-Device-ID' => 'sponsored-preparation-device',
        ];
    }

    public function test_unfunded_unpaid_order_cannot_request_extension(): void
    {
        [$order, $guest, $user] = $this->sponsoredFixture();

        DB::table('sponsored_order_fundings')
            ->where('order_id', $order->id)
            ->delete();

        $this->assertFalse($order->fresh()->isSponsoredFunded());

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/v1/app/family/orders/{$order->id}/preparation-decision",
            [
                'kind' => 'extension',
                'extra_minutes' => 5,
                'compensation_sar' => 3,
                'reason' => 'نحتاج وقتًا إضافيًا لتجهيز الطلب',
            ]
        )->assertStatus(409);

        $this->assertDatabaseMissing('order_preparation_decisions', [
            'order_id' => $order->id,
        ]);
    }

    public function test_paid_order_with_pending_contact_cannot_request_extension(): void
    {
        [$order, $guest, $user] = $this->sponsoredFixture();

        DB::table('sponsored_order_fundings')
            ->where('order_id', $order->id)
            ->delete();

        $order->forceFill([
            'payment_status' => Order::PAYMENT_PAID,
            'contact_verification_state' => 'pending',
        ])->save();

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/v1/app/family/orders/{$order->id}/preparation-decision",
            [
                'kind' => 'extension',
                'extra_minutes' => 5,
                'compensation_sar' => 3,
                'reason' => 'نحتاج وقتًا إضافيًا لتجهيز الطلب',
            ]
        )->assertStatus(409);

        $this->assertDatabaseMissing('order_preparation_decisions', [
            'order_id' => $order->id,
        ]);
    }

    public function test_other_family_cannot_request_extension(): void
    {
        [$order, $guest, $user] = $this->sponsoredFixture();

        $otherUser = User::factory()->create();

        Sanctum::actingAs($otherUser);

        $this->postJson(
            "/api/v1/app/family/orders/{$order->id}/preparation-decision",
            [
                'kind' => 'extension',
                'extra_minutes' => 5,
                'compensation_sar' => 3,
                'reason' => 'محاولة تعديل طلب أسرة أخرى',
            ]
        )->assertStatus(403);

        $this->assertDatabaseMissing('order_preparation_decisions', [
            'order_id' => $order->id,
        ]);
    }
    public function test_sponsored_driver_offer_first_accept_preserves_funding(): void
    {
        [$order] = $this->sponsoredFixture();

        $order->forceFill([
            'status' => Order::STATUS_READY,
            'ready_at' => now()->subSeconds(2),
        ])->save();

        config()->set('zad_driver_offers.enabled', true);
        config()->set('zad_driver_offers.all_orders', false);
        config()->set('zad_driver_offers.test_order_ids', [(int) $order->id]);
        config()->set('zad_driver_offers.window_seconds', 20);

        $driverUser1 = User::factory()->create();
        $driverUser2 = User::factory()->create();

        $driver1 = \App\Models\Driver::query()->create([
            'user_id' => $driverUser1->id,
            'code' => 'SPD-DRV-A-' . $order->id,
            'name' => 'Sponsored Driver A',
            'phone' => '0500000021',
            'status' => 'active',
            'application_status' => 'approved',
            'is_online' => true,
            'active_orders_count' => 0,
            'vehicle_type' => 'scooter',
        ]);

        $driver2 = \App\Models\Driver::query()->create([
            'user_id' => $driverUser2->id,
            'code' => 'SPD-DRV-B-' . $order->id,
            'name' => 'Sponsored Driver B',
            'phone' => '0500000022',
            'status' => 'active',
            'application_status' => 'approved',
            'is_online' => true,
            'active_orders_count' => 0,
            'vehicle_type' => 'scooter',
        ]);

        $offers = app(\App\Services\DriverTaskOfferService::class);
        $operations = app(\App\Services\DeliveryOperationsService::class);

        $firstOffers = $offers->listFor($driver1);
        $secondOffers = $offers->listFor($driver2);

        $this->assertCount(1, $firstOffers);
        $this->assertCount(1, $secondOffers);
        $this->assertSame((int) $order->id, (int) $firstOffers[0]['id']);

        $expiry = $firstOffers[0]['expires_at'];

        $result = $offers->accept(
            $order,
            $driver1,
            $operations,
            $driverUser1->id,
            $expiry
        );

        $this->assertSame('assigned', $result['status']);

        $assigned = $order->fresh();

        $this->assertSame(Order::STATUS_ASSIGNED, $assigned->status);
        $this->assertSame((int) $driver1->id, (int) $assigned->driver_id);
        $this->assertSame(Order::PAYMENT_UNPAID, $assigned->payment_status);
        $this->assertTrue($assigned->isSponsoredFunded());

        $this->assertDatabaseHas('delivery_assignments', [
            'order_id' => $order->id,
            'driver_id' => $driver1->id,
            'status' => 'accepted',
        ]);

        $this->assertDatabaseHas('sponsored_order_fundings', [
            'order_id' => $order->id,
            'funding_source' => 'zad_platform',
            'status' => 'committed',
        ]);

        $this->assertSame([], $offers->listFor($driver2));

        try {
            $offers->accept(
                $order->fresh(),
                $driver2,
                $operations,
                $driverUser2->id,
                $expiry
            );

            $this->fail('Second driver unexpectedly accepted the order.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertSame(
            (int) $driver1->id,
            (int) $order->fresh()->driver_id
        );

        $this->assertDatabaseMissing('delivery_assignments', [
            'order_id' => $order->id,
            'driver_id' => $driver2->id,
            'status' => 'accepted',
        ]);
    }
    public function test_sponsored_settlement_records_platform_funding_once(): void
    {
        [$order] = $this->sponsoredFixture();

        config()->set('settlements.automatic_release_enabled', false);

        $order->forceFill([
            'status' => Order::STATUS_DELIVERED,
            'delivered_at' => now(),
        ])->save();

        $service = app(\App\Services\OrderSettlementService::class);

        $first = $service->prepare($order);
        $second = $service->prepare($order->fresh());

        $this->assertSame($first->id, $second->id);

        $this->assertEqualsWithDelta(
            -25.0,
            (float) $first->platform_total
                - (float) $first->family_commission
                - (float) $first->driver_commission,
            0.01
        );

        $snapshot = (array) $first->calculation_snapshot;

        $this->assertEqualsWithDelta(
            25.0,
            (float) ($snapshot['sponsored_funding_total'] ?? 0),
            0.01
        );

        $ledgerEntries = DB::table('financial_ledger_entries')
            ->where('external_reference', "ZAD-SPONSOR-{$order->id}")
            ->get();

        $this->assertCount(1, $ledgerEntries);
        $this->assertSame(
            'sponsorship_expense',
            $ledgerEntries[0]->account_code
        );
        $this->assertSame('debit', $ledgerEntries[0]->direction);
        $this->assertEqualsWithDelta(
            25.0,
            (float) $ledgerEntries[0]->amount,
            0.01
        );
        $this->assertDatabaseCount('order_settlements', 1);

        $this->assertSame(
            1,
            DB::table('wallet_transactions')
                ->where('reference', "SET-{$order->id}-FAMILY")
                ->count()
        );
    }
    public function test_sponsored_wallet_release_is_idempotent(): void
    {
        [$order] = $this->sponsoredFixture();

        config()->set('settlements.automatic_release_enabled', false);

        $driverUser = User::factory()->create();

        $driver = \App\Models\Driver::query()->create([
            'user_id' => $driverUser->id,
            'code' => 'SPD-SET-DRV-' . $order->id,
            'name' => 'Sponsored Settlement Driver',
            'phone' => '0500000099',
            'status' => 'active',
            'application_status' => 'approved',
            'is_online' => true,
            'active_orders_count' => 0,
            'vehicle_type' => 'scooter',
        ]);

        $order->forceFill([
            'driver_id' => $driver->id,
            'status' => Order::STATUS_DELIVERED,
            'delivered_at' => now(),
        ])->save();

        $service = app(\App\Services\OrderSettlementService::class);
        $settlement = $service->prepare($order);

        $this->assertNotNull($settlement->family_wallet_transaction_id);
        $this->assertNotNull($settlement->driver_wallet_transaction_id);

        $familyTx = DB::table('wallet_transactions')
            ->where('id', $settlement->family_wallet_transaction_id)
            ->first();

        $driverTx = DB::table('wallet_transactions')
            ->where('id', $settlement->driver_wallet_transaction_id)
            ->first();

        $this->assertEqualsWithDelta(
            (float) $settlement->family_net,
            (float) $familyTx->amount,
            0.01
        );

        $this->assertEqualsWithDelta(
            (float) $settlement->driver_net,
            (float) $driverTx->amount,
            0.01
        );

        $first = $service->release($settlement, null, true);
        $second = $service->release($first, null, true);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            \App\Models\OrderSettlement::STATUS_RELEASED,
            $second->status
        );

        foreach ([$familyTx, $driverTx] as $transaction) {
            $wallet = DB::table('wallets')
                ->where('id', $transaction->wallet_id)
                ->first();

            $this->assertEqualsWithDelta(
                (float) $transaction->amount,
                (float) $wallet->available_balance,
                0.01
            );

            $this->assertEqualsWithDelta(
                0.0,
                (float) $wallet->pending_balance,
                0.01
            );

            $this->assertSame(
                1,
                DB::table('financial_ledger_entries')
                    ->where('account_code', 'wallet_liability')
                    ->where('source_type', \App\Models\WalletTransaction::class)
                    ->where('source_id', $transaction->id)
                    ->count()
            );
        }

        $this->assertSame(
            1,
            DB::table('financial_ledger_entries')
                ->where('external_reference', "ZAD-SPONSOR-{$order->id}")
                ->count()
        );
    }
    public function test_sponsored_pending_decision_blocks_settlement_preparation(): void
    {
        [$order] = $this->sponsoredFixture();

        config()->set('zad_v4_family_actions.enabled', true);

        $order->forceFill([
            'status' => Order::STATUS_DELIVERED,
            'delivered_at' => now(),
        ])->save();

        \App\Models\OrderPreparationDecision::query()->create([
            'order_id' => $order->id,
            'family_id' => $order->store->productive_family_id,
            'kind' => 'extension',
            'reason' => 'Sponsored preparation extension test',
            'status' => 'pending',
            'extra_minutes' => 5,
            'compensation_halalas' => 300,
            'compensation_state' => 'none',
            'response_due_at' => now()->addMinutes(10),
        ]);

        try {
            app(\App\Services\OrderSettlementService::class)
                ->prepare($order);

            $this->fail('Pending decision unexpectedly allowed settlement.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertDatabaseMissing('order_settlements', [
            'order_id' => $order->id,
        ]);

        $this->assertDatabaseMissing('wallet_transactions', [
            'reference' => "SET-{$order->id}-FAMILY",
        ]);

        $this->assertDatabaseMissing('wallet_transactions', [
            'reference' => "SET-{$order->id}-DRIVER",
        ]);
    }

    public function test_sponsored_manual_review_blocks_settlement_release(): void
    {
        [$order] = $this->sponsoredFixture();

        config()->set('zad_v4_family_actions.enabled', true);
        config()->set('settlements.automatic_release_enabled', false);

        $order->forceFill([
            'status' => Order::STATUS_DELIVERED,
            'delivered_at' => now(),
        ])->save();

        $settlement = app(\App\Services\OrderSettlementService::class)
            ->prepare($order);

        \App\Models\OrderPreparationDecision::query()->create([
            'order_id' => $order->id,
            'family_id' => $order->store->productive_family_id,
            'kind' => 'extension',
            'reason' => 'Sponsored preparation extension test',
            'status' => 'accepted',
            'extra_minutes' => 5,
            'compensation_halalas' => 300,
            'compensation_state' => 'manual_review',
            'response_due_at' => now()->addMinutes(10),
            'responded_at' => now(),
        ]);

        try {
            app(\App\Services\OrderSettlementService::class)
                ->release($settlement, null, true);

            $this->fail('Manual-review compensation unexpectedly allowed release.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertSame(
            \App\Models\OrderSettlement::STATUS_PENDING,
            $settlement->fresh()->status
        );

        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);
    }
    public function test_sponsored_customer_accepts_extension(): void
    {
        [$order, $guest, $user] = $this->sponsoredFixture();

        $this->requestExtension($order, $user);

        auth()->forgetGuards();

        $this->withHeaders($this->guestHeaders($guest))
            ->postJson(
                "/api/v1/app/orders/{$order->id}/preparation-decision/response",
                ['response' => 'accept']
            )
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.no_refund_executed', true);

        $this->assertNotNull(
            $order->fresh()->preparation_extended_until
        );

        $this->assertTrue(
            app(OrderPreparationDecisionGuard::class)
                ->hasBlockingSettlement($order->fresh())
        );
    }

    public function test_sponsored_customer_declines_extension(): void
    {
        [$order, $guest, $user] = $this->sponsoredFixture();

        $this->requestExtension($order, $user);

        auth()->forgetGuards();

        $this->withHeaders($this->guestHeaders($guest))
            ->postJson(
                "/api/v1/app/orders/{$order->id}/preparation-decision/response",
                ['response' => 'decline']
            )
            ->assertOk()
            ->assertJsonPath('data.status', 'refund_review')
            ->assertJsonPath('data.no_refund_executed', true);

        $this->assertTrue(
            app(OrderPreparationDecisionGuard::class)
                ->hasBlockingSettlement($order->fresh())
        );
    }

    public function test_guest_without_token_cannot_respond(): void
    {
        [$order, $guest, $user] = $this->sponsoredFixture();

        $this->requestExtension($order, $user);

        auth()->forgetGuards();

        $this->withHeaders([
            'X-Guest-Session' => (string) $guest->id,
        ])->postJson(
            "/api/v1/app/orders/{$order->id}/preparation-decision/response",
            ['response' => 'accept']
        )->assertStatus(401);

        $this->assertDatabaseHas('order_preparation_decisions', [
            'order_id' => $order->id,
            'status' => 'pending',
        ]);
    }

    public function test_sponsored_response_does_not_create_payment_attempt(): void
    {
        [$order, $guest, $user] = $this->sponsoredFixture();

        $this->requestExtension($order, $user);

        auth()->forgetGuards();

        \Illuminate\Support\Facades\Http::preventStrayRequests();

        $this->withHeaders($this->guestHeaders($guest))
            ->postJson(
                "/api/v1/app/orders/{$order->id}/preparation-decision/response",
                ['response' => 'accept']
            )
            ->assertOk();

        $this->assertDatabaseCount('zad_payment_attempts', 0);

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }
    public function test_family_can_request_extension_for_sponsored_order(): void
    {
        $tag = bin2hex(random_bytes(5));

        $family = ProductiveFamily::query()->create([
            'code' => 'SPD-' . $tag,
            'owner_name' => 'Test Family',
            'phone' => '0500000011',
            'status' => 'active',
        ]);

        $store = Store::query()->create([
            'productive_family_id' => $family->id,
            'name_ar' => 'Test Sponsored Store',
            'slug' => 'spd-' . $tag,
            'status' => 'active',
            'is_open' => true,
        ]);

        $user = User::factory()->create();

        AppProfile::query()->create([
            'user_id' => $user->id,
            'productive_family_id' => $family->id,
            'roles' => ['family'],
            'active_mode' => 'family',
        ]);

        $guest = AppGuestSession::query()->create([
            'device_id' => 'sponsored-preparation-device',
            'last_seen_at' => now(),
        ]);

        $order = Order::query()->create([
            'number' => 'SPD-' . strtoupper($tag),
            'store_id' => $store->id,
            'guest_session_id' => $guest->id,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
            'contact_verification_state' => 'pending',
            'subtotal' => 20,
            'delivery_fee' => 5,
            'discount' => 0,
            'tax' => 0,
            'total' => 25,
        ]);

        // This test inserts a committed funding record directly.
        // Coupon redemption validity is covered by separate tests.
        $couponOwner = User::factory()->create();

        $creatorId = DB::table('launch_creators')->insertGetId([
            'code' => 'SPD-C-' . $tag,
            'name' => 'Test Creator',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $couponId = DB::table('sponsored_creator_coupons')->insertGetId([
            'creator_id' => $creatorId,
            'store_id' => $store->id,
            'created_by' => $couponOwner->id,
            'code_hash' => hash('sha256', 'SPD-' . $tag),
            'allowed_products' => json_encode([]),
            'gross_cap' => 50,
            'status' => 'consumed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sponsored_order_fundings')->insert([
            'coupon_id' => $couponId,
            'order_id' => $order->id,
            'funding_source' => 'zad_platform',
            'currency' => 'SAR',
            'products_amount' => 20,
            'delivery_amount' => 5,
            'tax_amount' => 0,
            'funded_total' => 25,
            'status' => 'committed',
            'idempotency_key' => 'spd-' . $tag,
            'committed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($order->fresh()->isSponsoredFunded());

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/v1/app/family/orders/{$order->id}/preparation-decision",
            [
                'kind' => 'extension',
                'extra_minutes' => 5,
                'compensation_sar' => 3,
                'reason' => 'نحتاج وقتًا إضافيًا لتجهيز الطلب',
            ]
        )->assertCreated()
         ->assertJsonPath('data.kind', 'extension')
         ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('order_preparation_decisions', [
            'order_id' => $order->id,
            'family_id' => $family->id,
            'kind' => 'extension',
            'compensation_halalas' => 300,
            'compensation_state' => 'manual_review',
        ]);

        $this->assertTrue(
            app(OrderPreparationDecisionGuard::class)
                ->hasBlockingSettlement($order->fresh())
        );
    }
}