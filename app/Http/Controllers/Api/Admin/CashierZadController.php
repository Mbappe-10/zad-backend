<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FamilyCashierGrant;
use App\Models\FamilyStoreGrant;
use App\Models\FamilyStorePlan;
use App\Models\FamilyStorePurchase;
use App\Models\ProductiveFamily;
use App\Models\SaleReceipt;
use App\Models\Store;
use App\Services\App\CashierZadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Owner governance for Cashier Zad: price, features, launch offer and Pro time. */
class CashierZadController extends Controller
{
    public function __construct(private readonly CashierZadService $cashier) {}

    public function settings(Request $request): JsonResponse
    {
        $this->owner($request);

        return response()->json(['data' => $this->cashier->settings()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->owner($request);
        $keys = array_keys(CashierZadService::FEATURES);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'price' => ['sometimes', 'numeric', 'min:1', 'max:100000'],
            'duration_days' => ['sometimes', 'integer', 'between:1,3650'],
            'selling_enabled' => ['sometimes', 'boolean'],
            'available_for' => ['sometimes', 'array'],
            'available_for.*' => ['in:free,silver,gold'],
            'basic_for' => ['sometimes', 'array'],
            'basic_for.*' => ['in:free,silver,gold'],
            'features' => ['sometimes', 'array'],
            'features.*.basic' => ['sometimes', 'boolean'],
            'features.*.pro' => ['sometimes', 'boolean'],
            'launch_offer' => ['sometimes', 'array'],
            'launch_offer.enabled' => ['sometimes', 'boolean'],
            'launch_offer.plan' => ['sometimes', 'in:free,silver,gold,all'],
            'launch_offer.days' => ['sometimes', 'integer', 'between:1,365'],
            'launch_offer.until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        abort_if(isset($data['features']) && array_diff(array_keys($data['features']), $keys) !== [], 422, 'ميزة غير معروفة.');

        $plan = DB::transaction(function () use ($data, $request): FamilyStorePlan {
            $plan = FamilyStorePlan::query()->where('code', CashierZadService::PLAN_CODE)->lockForUpdate()->firstOrFail();
            $before = $this->cashier->settings($plan);
            $caps = is_array($plan->capabilities) ? $plan->capabilities : [];
            foreach ($data['features'] ?? [] as $key => $row) {
                $caps['features'][$key] = array_replace($caps['features'][$key] ?? [], array_intersect_key($row, array_flip(['basic', 'pro'])));
            }
            if (array_key_exists('available_for', $data)) {
                $caps['available_for'] = array_values(array_unique($data['available_for']));
            }
            if (array_key_exists('basic_for', $data)) {
                $caps['basic_for'] = array_values(array_unique($data['basic_for']));
            }
            if (isset($data['launch_offer'])) {
                $caps['launch_offer'] = array_replace((array) ($caps['launch_offer'] ?? []), $data['launch_offer']);
            }
            $plan->fill(array_filter([
                'name' => $data['name'] ?? null,
                'price' => isset($data['price']) ? number_format((float) $data['price'], 2, '.', '') : null,
                'duration_days' => $data['duration_days'] ?? null,
            ], fn ($v) => $v !== null));
            if (array_key_exists('description', $data)) {
                $plan->description = $data['description'];
            }
            if (array_key_exists('selling_enabled', $data)) {
                $plan->is_visible = $data['selling_enabled'];
            }
            $plan->capabilities = $caps;
            $plan->save();
            Log::info('cashier zad settings changed', ['by' => $request->user()->id, 'before' => $before, 'after' => $this->cashier->settings($plan)]);

            return $plan;
        });

        return response()->json(['message' => 'تم حفظ إعدادات كاشير زاد.', 'data' => $this->cashier->settings($plan)]);
    }

    public function subscribers(Request $request): JsonResponse
    {
        $this->owner($request);
        $grants = FamilyCashierGrant::query()->whereNull('revoked_at')->where('ends_at', '>', now())->get()->groupBy('productive_family_id');
        $basicFor = array_values(array_diff($this->cashier->settings()['basic_for'], ['free']));
        $gold = FamilyStoreGrant::query()->whereIn('plan_code', $basicFor ?: ['__none__'])->whereNull('revoked_at')
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->pluck('productive_family_id')->unique();
        $ids = $grants->keys()->merge($gold)->unique()->values();
        $families = ProductiveFamily::query()->whereIn('id', $ids)->get(['id', 'owner_name'])->keyBy('id');
        $stores = Store::query()->whereIn('productive_family_id', $ids)->get(['id', 'productive_family_id', 'name_ar'])->keyBy('productive_family_id');
        $monthStart = now('Asia/Riyadh')->startOfMonth()->utc();
        $sales = SaleReceipt::query()->where('sale_type', '!=', SaleReceipt::TYPE_PLATFORM)->where('status', 'paid')
            ->where('issued_at', '>=', $monthStart)->whereIn('productive_family_id', $ids)
            ->selectRaw('productive_family_id, count(*) as c, sum(total) as t')->groupBy('productive_family_id')->get()->keyBy('productive_family_id');

        $rows = $ids->map(function ($id) use ($grants, $gold, $families, $stores, $sales): array {
            $active = ($grants[$id] ?? collect())->sortByDesc('ends_at');
            $current = $active->first(fn ($g) => $g->starts_at->lte(now()));

            return [
                'family_id' => $id,
                'family_name' => $families[$id]->owner_name ?? null,
                'store_name' => $stores[$id]->name_ar ?? null,
                'store_plan' => app(\App\Services\App\FamilyStorefrontService::class)
                    ->catalogEntitlement(ProductiveFamily::query()->find($id))['plan'] ?? 'free',
                'tier' => $active->isNotEmpty() ? 'pro' : 'basic',
                'source' => $current?->source ?? ($active->first()?->source ?? 'plan'),
                'ends_at' => $active->first()?->ends_at?->toISOString(),
                'grants' => $active->map(fn ($g) => ['id' => $g->id, 'source' => $g->source, 'starts_at' => $g->starts_at->toISOString(),
                    'ends_at' => $g->ends_at->toISOString(), 'reason' => $g->reason])->values(),
                'month_sales' => round((float) ($sales[$id]->t ?? 0), 2),
                'month_sales_count' => (int) ($sales[$id]->c ?? 0),
            ];
        })->sortByDesc('month_sales')->values();
        $paid = FamilyStorePurchase::query()->where('plan_code', CashierZadService::PLAN_CODE)->where('status', 'paid')
            ->where('paid_at', '>=', $monthStart)->sum('amount_halalas');

        return response()->json([
            'data' => $rows,
            'summary' => [
                'pro' => $rows->where('tier', 'pro')->count(),
                'pro_paid' => $rows->where('tier', 'pro')->where('source', 'purchase')->count(),
                'pro_launch' => $rows->where('tier', 'pro')->where('source', 'launch_offer')->count(),
                'basic' => $rows->where('tier', 'basic')->count(),
                'revenue_month' => round($paid / 100, 2),
                'direct_sales_month' => round((float) $rows->sum('month_sales'), 2),
            ],
            'families' => ProductiveFamily::query()->orderBy('owner_name')->limit(500)->get(['id', 'owner_name'])
                ->map(fn ($f) => ['id' => $f->id, 'name' => $f->owner_name, 'store' => Store::query()->where('productive_family_id', $f->id)->value('name_ar')]),
        ]);
    }

    public function grant(Request $request): JsonResponse
    {
        $this->owner($request);
        $data = $request->validate([
            'productive_family_id' => ['required', 'integer', 'exists:productive_families,id'],
            'duration' => ['required', 'integer', 'min:1', 'max:3650'],
            'duration_unit' => ['required', 'in:hours,days'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $seconds = $data['duration'] * ($data['duration_unit'] === 'hours' ? 3600 : 86400);
        $grant = DB::transaction(function () use ($data, $seconds, $request): FamilyCashierGrant {
            ProductiveFamily::query()->whereKey($data['productive_family_id'])->lockForUpdate()->firstOrFail();

            return $this->cashier->addTime($data['productive_family_id'], $seconds, 'owner_grant', $data['reason'], null, $request->user()->id);
        });

        return response()->json(['message' => 'تم منح كاشير زاد برو.', 'data' => $grant], 201);
    }

    public function revoke(Request $request, FamilyCashierGrant $grant): JsonResponse
    {
        $this->owner($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        abort_if($grant->revoked_at !== null, 422, 'المنحة ملغاة مسبقاً.');
        $grant->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id, 'revocation_reason' => $data['reason']]);

        return response()->json(['message' => 'تم سحب كاشير زاد برو.']);
    }

    private function owner(Request $request): void
    {
        abort_unless($request->user()?->isPlatformOwner(), 403, 'إدارة كاشير زاد خاصة بمالك المنصة.');
    }
}
