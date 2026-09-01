<?php

namespace App\Services;

use App\Models\City;
use App\Models\DeliveryPricingRule;
use App\Models\Vehicle;

class VehiclePricingService
{
    public function quote(?int $cityId, string $vehicleType, float $distanceKm): float
    {
        $vehicle = Vehicle::query()
            ->where('type', $vehicleType)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        $rule = DeliveryPricingRule::query()
            ->where('is_active', true)
            ->where(function ($query) use ($cityId): void {
                $query->whereNull('city_id');
                if ($cityId !== null) {
                    $query->orWhere('city_id', $cityId);
                }
            })
            ->where(function ($query) use ($vehicle): void {
                $query->whereNull('vehicle_id');
                if ($vehicle !== null) {
                    $query->orWhere('vehicle_id', $vehicle->id);
                }
            })
            ->orderByRaw('CASE WHEN vehicle_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN city_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('priority')
            ->first();

        $cityBaseFee = $cityId !== null
            ? (float) (City::query()->whereKey($cityId)->value('delivery_base_fee') ?? 0)
            : 0;

        $base = (float) ($rule?->base_fee ?? $vehicle?->base_fee ?? $cityBaseFee);
        $perKm = (float) ($rule?->per_km_fee ?? $vehicle?->per_km_fee ?? 0);
        $minimum = (float) ($rule?->minimum_fee ?? 0);
        $multiplier = max((float) ($rule?->surge_multiplier ?? 1), 1);

        return round(
            max($minimum, ($base + ($perKm * max($distanceKm, 0))) * $multiplier),
            2,
        );
    }
}
