<?php

namespace App\Services\App;

use App\Models\FamilyCashierGrant;
use App\Models\FamilyStorePlan;
use App\Models\ProductiveFamily;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier Zad entitlement. Basic comes with the gold store plan; Pro is its own
 * subscription for any plan. Every price, feature switch and launch offer is
 * read from the cashier_pro plan row that the owner edits from the dashboard.
 */
class CashierZadService
{
    public const PLAN_CODE = 'cashier_pro';

    public const FEATURES = [
        'quick_sale' => 'البيع السريع والإيصال',
        'daily_stock' => 'طبخة اليوم والكميات',
        'day_close' => 'إقفال اليوم',
        'reports' => 'التقارير والتصدير',
        'batches' => 'دفعات الإنتاج',
        'batch_costing' => 'تكلفة الدفعة وربحها',
        'bulk_orders' => 'طلبات الكميات والعربون',
        'credit_book' => 'دفتر الآجل',
        'assistants' => 'المساعدات والصلاحيات',
        'bazaar_mode' => 'وضع المعارض',
    ];

    private const DEFAULT_BASIC = ['quick_sale', 'daily_stock', 'day_close', 'reports'];

    public function __construct(private readonly FamilyStorefrontService $storefront) {}

    public function ready(): bool
    {
        return Schema::hasTable('family_cashier_grants');
    }

    public function plan(): ?FamilyStorePlan
    {
        return FamilyStorePlan::query()->where('code', self::PLAN_CODE)->first();
    }

    /** Dashboard settings with defaults for anything missing. */
    public function settings(?FamilyStorePlan $plan = null): array
    {
        $plan ??= $this->plan();
        $caps = is_array($plan?->capabilities) ? $plan->capabilities : [];
        $features = [];
        foreach (self::FEATURES as $key => $label) {
            $row = $caps['features'][$key] ?? [];
            $features[$key] = [
                'label' => $label,
                'basic' => (bool) ($row['basic'] ?? in_array($key, self::DEFAULT_BASIC, true)),
                'pro' => (bool) ($row['pro'] ?? true),
            ];
        }
        $offer = (array) ($caps['launch_offer'] ?? []);

        return [
            'name' => $plan?->name ?? 'كاشير زاد برو',
            'description' => $plan?->description,
            'price' => (string) ($plan?->price ?? '19.00'),
            'duration_days' => (int) ($plan?->duration_days ?? 30),
            'selling_enabled' => (bool) ($plan?->is_visible ?? false),
            'available_for' => array_values((array) ($caps['available_for'] ?? ['free', 'silver', 'gold'])),
            // Store plans that include the basic cashier (owner's choice; gold by default).
            'basic_for' => array_values((array) ($caps['basic_for'] ?? ['gold'])),
            'features' => $features,
            'launch_offer' => [
                'enabled' => (bool) ($offer['enabled'] ?? false),
                'plan' => (string) ($offer['plan'] ?? 'gold'),
                'days' => (int) ($offer['days'] ?? 90),
                'until' => $offer['until'] ?? null,
            ],
        ];
    }

    public function activeProGrant(int $familyId): ?FamilyCashierGrant
    {
        if (! $this->ready()) {
            return null;
        }

        return FamilyCashierGrant::query()->where('productive_family_id', $familyId)->whereNull('revoked_at')
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->orderByDesc('ends_at')->first();
    }

    /** Tier and feature switches for a family right now. */
    public function entitlement(?ProductiveFamily $family): array
    {
        $settings = $this->settings();
        $storePlan = $family ? ($this->storefront->catalogEntitlement($family)['plan'] ?? 'free') : 'free';
        $pro = $family ? $this->activeProGrant($family->id) : null;
        $tier = $pro ? 'pro' : (in_array($storePlan, $settings['basic_for'], true) ? 'basic' : 'none');
        $features = [];
        foreach ($settings['features'] as $key => $row) {
            $features[$key] = $tier !== 'none' && (bool) $row[$tier];
        }
        $lastEnd = $family && $this->ready() ? FamilyCashierGrant::query()->where('productive_family_id', $family->id)
            ->whereNull('revoked_at')->max('ends_at') : null;

        return [
            'tier' => $tier,
            'store_plan' => $storePlan,
            'source' => $pro?->source ?? ($tier === 'basic' ? $storePlan : null),
            'pro_ends_at' => $lastEnd && $pro ? Carbon::parse($lastEnd)->toISOString() : null,
            'features' => $features,
            'can_buy_pro' => $settings['selling_enabled'] && in_array($storePlan, $settings['available_for'], true),
            'price' => $settings['price'],
            'duration_days' => $settings['duration_days'],
            'launch_offer' => $family ? $this->launchOfferFor($family, $storePlan, $settings) : null,
        ];
    }

    public function launchOfferFor(ProductiveFamily $family, string $storePlan, ?array $settings = null): ?array
    {
        $offer = ($settings ?? $this->settings())['launch_offer'];
        if (! $offer['enabled'] || $offer['days'] < 1 || ! $this->ready()) {
            return null;
        }
        if ($offer['until'] && Carbon::parse($offer['until'])->endOfDay()->isPast()) {
            return null;
        }
        if ($offer['plan'] !== 'all' && $storePlan !== $offer['plan']) {
            return null;
        }
        $used = FamilyCashierGrant::query()->where('productive_family_id', $family->id)->where('source', 'launch_offer')->exists();

        return $used ? null : ['days' => $offer['days'], 'until' => $offer['until']];
    }

    /** Pro time is added after any Pro time the family already has. */
    public function addTime(int $familyId, int $seconds, string $source, ?string $reason = null, ?int $purchaseId = null, ?int $by = null): FamilyCashierGrant
    {
        abort_unless($seconds >= 3600 && $seconds <= 3650 * 86400, 422, 'المدة غير صحيحة.');
        $last = FamilyCashierGrant::query()->where('productive_family_id', $familyId)->whereNull('revoked_at')
            ->where('ends_at', '>', now())->max('ends_at');
        $start = $last ? Carbon::parse($last) : now();

        return FamilyCashierGrant::query()->create([
            'productive_family_id' => $familyId,
            'plan_code' => 'pro',
            'source' => $source,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addSeconds($seconds),
            'purchase_id' => $purchaseId,
            'created_by' => $by,
            'reason' => $reason,
        ]);
    }
}
