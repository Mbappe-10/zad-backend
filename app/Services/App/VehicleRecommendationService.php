<?php

namespace App\Services\App;

use App\Models\AppSetting;
use Illuminate\Validation\ValidationException;

class VehicleRecommendationService
{
    public function recommend(string $size, float $distance): string
    {
        $gate = app(PlatformOperationGate::class);
        $enabledVehicles = [
            'scooter' => $gate->enabled('delivery', 'scooterEnabled', true),
            'motorcycle' => $gate->enabled('delivery', 'motorcycleEnabled', true),
            'car' => $gate->enabled('delivery', 'carEnabled', true),
        ];
        $maxDistances = [
            'scooter' => $gate->number('delivery', 'scooterMaximumDistanceKm', 10),
            'motorcycle' => $gate->number('delivery', 'motorcycleMaximumDistanceKm', 15),
        ];

        $rules = AppSetting::where('key', 'delivery.vehicle_rules')->value('value') ?? [];
        if (is_string($rules)) {
            $rules = json_decode($rules, true) ?: [];
        }

        usort($rules, fn ($a, $b) => ($a['priority'] ?? 100) <=> ($b['priority'] ?? 100));

        foreach ($rules as $rule) {
            $vehicle = (string) ($rule['vehicle'] ?? '');
            if (! ($enabledVehicles[$vehicle] ?? true)) {
                continue;
            }
            $ruleMax = $rule['max_distance_km'] ?? null;
            $controlMax = $maxDistances[$vehicle] ?? null;
            $effectiveMax = ($ruleMax !== null && $controlMax !== null)
                ? min((float) $ruleMax, (float) $controlMax)
                : ($controlMax ?? ($ruleMax !== null ? (float) $ruleMax : null));

            if (in_array($size, $rule['allowed_sizes'] ?? [], true) && ($effectiveMax === null || $distance <= $effectiveMax)) {
                return $vehicle;
            }
        }

        foreach (['car', 'motorcycle', 'scooter'] as $fallback) {
            if ($enabledVehicles[$fallback] ?? false) {
                return $fallback;
            }
        }

        throw ValidationException::withMessages([
            'delivery' => ['جميع أنواع مركبات التوصيل متوقفة حاليًا من مركز التحكم.'],
        ]);
    }

    public function assertOverrideAllowed(): void
    {
        $enabled = (bool) AppSetting::where('key', 'delivery.owner_override_enabled')->value('value');
        if (! $enabled) {
            throw ValidationException::withMessages(['vehicle_type' => 'تجاوز قاعدة المركبة متوقف من إعدادات المالك.']);
        }
    }
}