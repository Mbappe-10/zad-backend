<?php

namespace App\Services\App;

use App\Models\FamilyStoreGrant;
use App\Models\FamilyStorePlan;
use App\Models\ProductiveFamily;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FamilyStorefrontService
{
    public const RANKS = ['free' => 0, 'silver' => 1, 'gold' => 2];

    public static function rank(?string $plan): int
    {
        return self::RANKS[$plan ?? 'free'] ?? 0;
    }

    /** The highest plan active right now wins; a queued lower plan never hides a higher one. */
    public function currentGrant(int $familyId): ?FamilyStoreGrant
    {
        return FamilyStoreGrant::query()
            ->where('productive_family_id', $familyId)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->get()
            ->sortByDesc(fn (FamilyStoreGrant $grant): string => self::rank($grant->plan_code).'|'.$grant->ends_at->format('U'))
            ->first();
    }

    /** Renewals and downgrades wait for every equal-or-higher plan; upgrades start now. */
    public function nextStart(int $familyId, string $planCode): Carbon
    {
        $rank = self::rank($planCode);
        $end = FamilyStoreGrant::query()
            ->where('productive_family_id', $familyId)
            ->whereNull('revoked_at')
            ->where('ends_at', '>', now())
            ->get()
            ->filter(fn (FamilyStoreGrant $grant): bool => self::rank($grant->plan_code) >= $rank)
            ->max('ends_at');

        return $end instanceof Carbon && $end->isFuture() ? $end->copy() : now();
    }

    /**
     * Activates a paid plan. Buying a higher plan applies immediately; the unused
     * paid time of lower plans is converted at the plans' price-per-day ratio and
     * added to the new plan, so nothing the family paid for is lost.
     */
    public function activatePurchase(int $familyId, string $planCode, int $durationDays, string $reason): FamilyStoreGrant
    {
        $rank = self::rank($planCode);
        $creditSeconds = 0;
        $target = FamilyStorePlan::query()->where('code', $planCode)->first();
        $targetRate = $this->pricePerSecond($target);

        $lower = FamilyStoreGrant::query()
            ->where('productive_family_id', $familyId)
            ->where('source', 'purchase')
            ->whereNull('revoked_at')
            ->where('ends_at', '>', now())
            ->lockForUpdate()
            ->get()
            ->filter(fn (FamilyStoreGrant $grant): bool => self::rank($grant->plan_code) < $rank);

        foreach ($lower as $grant) {
            $from = $grant->starts_at->isFuture() ? $grant->starts_at : now();
            $remaining = max(0, $grant->ends_at->timestamp - $from->timestamp);
            $rate = $this->pricePerSecond(FamilyStorePlan::query()->where('code', $grant->plan_code)->first());
            if ($remaining > 0 && $rate > 0 && $targetRate > 0) {
                $creditSeconds += (int) floor($remaining * $rate / $targetRate);
            }
            if ($grant->starts_at->isFuture()) {
                $grant->update(['revoked_at' => now(), 'revoked_by_role' => 'system',
                    'revocation_reason' => 'converted into '.$planCode.' upgrade']);
            } else {
                $grant->update(['ends_at' => now(), 'revoked_by_role' => 'system',
                    'revocation_reason' => 'upgraded to '.$planCode]);
            }
        }

        $start = $this->nextStart($familyId, $planCode);

        return FamilyStoreGrant::query()->create([
            'productive_family_id' => $familyId,
            'plan_code' => $planCode,
            'source' => 'purchase',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays($durationDays)->addSeconds($creditSeconds),
            'reason' => $reason.($creditSeconds > 0 ? ' (+'.round($creditSeconds / 86400, 1).' days upgrade credit)' : ''),
        ]);
    }

    /** Ends every active and queued plan of the family. Refunds happen in Moyasar, not here. */
    public function cancelAll(int $familyId, string $role, ?int $userId, string $reason): int
    {
        $grants = FamilyStoreGrant::query()
            ->where('productive_family_id', $familyId)
            ->whereNull('revoked_at')
            ->where('ends_at', '>', now())
            ->lockForUpdate()
            ->get();
        foreach ($grants as $grant) {
            $grant->update(['revoked_at' => now(), 'revoked_by' => $userId,
                'revoked_by_role' => $role, 'revocation_reason' => $reason]);
        }

        return $grants->count();
    }

    /**
     * How many products the family's current plan allows. 0 means unlimited.
     * Falls back to the platform-wide limit when a plan does not define one.
     */
    public function productLimit(Store $store, int $platformLimit): int
    {
        $capabilities = $this->catalogEntitlement($store->productiveFamily)['capabilities'] ?? [];
        if (array_key_exists('max_products', $capabilities) && is_numeric($capabilities['max_products'])) {
            return max(0, (int) $capabilities['max_products']);
        }

        return max(1, $platformLimit);
    }

        private function pricePerSecond(?FamilyStorePlan $plan): float
    {
        if (! $plan || (int) $plan->duration_days < 1) {
            return 0.0;
        }

        return (float) $plan->price / ((int) $plan->duration_days * 86400);
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
            'grant_id' => $grant?->id,
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
