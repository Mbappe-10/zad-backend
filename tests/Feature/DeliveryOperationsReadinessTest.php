<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Order;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Services\DeliveryOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DeliveryOperationsReadinessTest extends TestCase
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

        config()->set('zad_driver_offers.enabled', false);
    }

    public function test_direct_assignment_rejects_an_order_before_it_is_ready(): void
    {
        [$order, $driver] = $this->fixture(Order::STATUS_ACCEPTED);

        try {
            app(DeliveryOperationsService::class)->assign(
                $order,
                $driver,
                null,
            );

            $this->fail('An accepted order was assigned before it was ready.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }

        $this->assertNull($order->fresh()->driver_id);
        $this->assertDatabaseMissing('delivery_assignments', [
            'order_id' => $order->id,
        ]);
    }

    public function test_automatic_assignment_rejects_an_order_before_it_is_ready(): void
    {
        [$order] = $this->fixture(Order::STATUS_PREPARING);

        try {
            app(DeliveryOperationsService::class)->autoAssign(
                $order,
                null,
            );

            $this->fail('A preparing order was auto-assigned before it was ready.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }

        $this->assertNull($order->fresh()->driver_id);
        $this->assertDatabaseMissing('delivery_assignments', [
            'order_id' => $order->id,
        ]);
    }

    public function test_ready_order_can_still_be_assigned(): void
    {
        [$order, $driver] = $this->fixture(Order::STATUS_READY);

        $assignment = app(DeliveryOperationsService::class)->assign(
            $order,
            $driver,
            null,
        );

        $this->assertSame((int) $driver->id, (int) $assignment->driver_id);
        $this->assertSame(Order::STATUS_ASSIGNED, $order->fresh()->status);
        $this->assertSame((int) $driver->id, (int) $order->fresh()->driver_id);
    }

    /**
     * @return array{0: Order, 1: Driver}
     */
    private function fixture(string $status): array
    {
        $tag = bin2hex(random_bytes(5));

        $family = ProductiveFamily::query()->create([
            'code' => 'RDY-'.$tag,
            'owner_name' => 'Readiness Test Family',
            'phone' => '05'.substr(hash('sha256', $tag), 0, 8),
            'status' => 'active',
        ]);

        $store = Store::query()->create([
            'productive_family_id' => $family->id,
            'name_ar' => 'Readiness Test Store',
            'slug' => 'readiness-'.$tag,
            'status' => 'active',
            'is_open' => true,
        ]);

        $order = Order::query()->create([
            'number' => 'RDY-'.strtoupper($tag),
            'store_id' => $store->id,
            'status' => $status,
            'payment_status' => Order::PAYMENT_PAID,
            'contact_verification_state' => 'verified',
            'contact_verified_at' => now(),
            'subtotal' => 20,
            'delivery_fee' => 5,
            'discount' => 0,
            'tax' => 0,
            'total' => 25,
        ]);

        $driver = Driver::query()->create([
            'code' => 'RDY-DRV-'.$tag,
            'name' => 'Readiness Test Driver',
            'phone' => '05'.substr(hash('sha256', 'driver-'.$tag), 0, 8),
            'status' => 'active',
            'application_status' => Driver::APPLICATION_APPROVED,
            'is_online' => true,
            'active_orders_count' => 0,
            'vehicle_type' => Driver::VEHICLE_SCOOTER,
        ]);

        return [$order, $driver];
    }
}
