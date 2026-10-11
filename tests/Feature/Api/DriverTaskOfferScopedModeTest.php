<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Services\DeliveryOperationsService;
use App\Services\DriverTaskOfferService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DriverTaskOfferScopedModeTest extends TestCase
{
    private function order(int $id): Order
    {
        $order = new Order();
        $order->id = $id;
        return $order;
    }

    public function test_off_means_old_dispatch_stays_unchanged(): void
    {
        config()->set('zad_driver_offers.enabled', false);
        config()->set('zad_driver_offers.test_order_ids', [101]);
        config()->set('zad_driver_offers.all_orders', false);
        $this->assertFalse(DriverTaskOfferService::enabledForOrder($this->order(101)));
    }

    public function test_pilot_is_restricted_to_specified_orders(): void
    {
        config()->set('zad_driver_offers.enabled', true);
        config()->set('zad_driver_offers.test_order_ids', [101]);
        config()->set('zad_driver_offers.all_orders', false);
        $this->assertTrue(DriverTaskOfferService::enabledForOrder($this->order(101)));
        $this->assertFalse(DriverTaskOfferService::enabledForOrder($this->order(102)));
    }

    public function test_enable_without_pilot_ids_is_safe_by_default(): void
    {
        config()->set('zad_driver_offers.enabled', true);
        config()->set('zad_driver_offers.test_order_ids', []);
        config()->set('zad_driver_offers.all_orders', false);
        $this->assertFalse(DriverTaskOfferService::enabledForOrder($this->order(101)));
    }

    public function test_pilot_order_is_not_auto_assigned_to_any_driver(): void
    {
        config()->set('zad_driver_offers.enabled', true);
        config()->set('zad_driver_offers.test_order_ids', [101]);
        config()->set('zad_driver_offers.all_orders', false);
        $service = app(DeliveryOperationsService::class);
        $this->expectException(ValidationException::class);
        $service->autoAssign($this->order(101), null);
    }
}
