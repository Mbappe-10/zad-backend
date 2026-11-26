<?php

namespace App\Services\App;

use App\Models\FamilyStoreGrant;
use App\Models\FamilyStorePlan;
use App\Models\ProductiveFamily;
use App\Models\Store;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FamilyStorefrontService
{
    public function currentGrant(int $familyId): ?FamilyStoreGrant
    {
        return FamilyStoreGrant::query()
            ->where('productive_family_id', $familyId)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();
    }

    public function entitlement(?ProductiveFamily $family): array
    {
        $grant = $family ? $this->currentGrant($family->id) : null;
        $plan = FamilyStorePlan::query()->where('code', $grant?->plan_code ?? 'free')->first();
        $plan ??= FamilyStorePlan::query()->where('code', 'free')->first();

        return [
            'plan' => $plan?->code ?? 'free',
            'capabilities' => $plan?->capabilities ?? [],
            'source' => $grant?->source ?? 'free',
            'starts_at' => $grant?->starts_at?->toISOString(),
            'ends_at' => $grant?->ends_at?->toISOString(),
        ];
    }

    // Cache schema availability for this HTTP request only. A worker can serve
    // the catalog before the subscriptions migration has finished.
    public function catalogPlansReady(): bool
    {
        $attributes = request()->attributes;
        $key = 'zad.catalog.family_plan_schema';
        if (! $attributes->has($key)) {
            $attributes->set($key, Schema::hasTable('family_store_grants') && Schema::hasTable('family_store_plans'));
        }

        return $attributes->get($key);
    }

    public function catalogEntitlement(?ProductiveFamily $family): array
    {
        if (! $this->catalogPlansReady()) {
            return ['plan' => 'free', 'capabilities' => []];
        }

        return $this->entitlement($family);
    }

    public function design(Store $store, array $entitlement): array
    {
        $stored = is_array($store->storefront_design) ? $store->storefront_design : [];
        $abilities = $entitlement['capabilities'];

        return [
            'banner_url' => ! empty($abilities['banner']) ? $this->url($store->cover_path) : null,
            'theme' => ! empty($abilities['themes']) ? ($stored['theme'] ?? 'default') : 'default',
            'accent_color' => ! empty($abilities['themes']) ? ($stored['accent_color'] ?? '#F97316') : '#F97316',
            'font' => ! empty($abilities['fonts']) ? ($stored['font'] ?? 'system') : 'system',
            'product_card' => ! empty($abilities['product_cards']) ? ($stored['product_card'] ?? 'standard') : 'standard',
        ];
    }

    public function publicStore(Store $store): array
    {
        $entitlement = $this->catalogEntitlement($store->productiveFamily);

        return [
            'id' => $store->id,
            'name_ar' => $store->name_ar,
            'description_ar' => $store->description_ar,
            'logo_url' => $this->url($store->logo_path),
            'rating' => (float) $store->rating,
            'rating_count' => (int) $store->rating_count,
            'working_hours' => $store->working_hours ?? [],
            'is_open' => (bool) $store->is_open,
            'plan' => $entitlement['plan'],
            'design' => $this->design($store, $entitlement),
        ];
    }

    private function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Str::startsWith($path, ['https://', 'http://']) ? $path : Storage::disk('public')->url($path);
    }
}
