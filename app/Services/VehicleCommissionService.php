<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Order;
use App\Models\Vehicle;

class VehicleCommissionService
{
    /**
     * Resolve ZAD's commission from the driver's delivery fee.
     *
     * @return array{0: float, 1: null}|null
     */
    public function resolve(Order $order, float $deliveryFee): ?array
    {
        if ($deliveryFee <= 0) {
            return [0.0, null];
        }

        $vehicleType = $order->assigned_vehicle_type
            ?: $order->recommended_vehicle_type
            ?: ($order->driver_id !== null
                ? Driver::query()->whereKey($order->driver_id)->value('vehicle_type')
                : null);

        if (blank($vehicleType)) {
            return null;
        }

        $vehicle = Vehicle::query()
            ->where('type', $vehicleType)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if ($vehicle === null || $vehicle->commission_value === null) {
            return null;
        }

        $value = max((float) $vehicle->commission_value, 0);
        $amount = $vehicle->commission_type === 'fixed'
            ? $value
            : $deliveryFee * min($value, 100) / 100;

        return [
            min(round(max($amount, 0), 2), round($deliveryFee, 2)),
            null,
        ];
    }
}
