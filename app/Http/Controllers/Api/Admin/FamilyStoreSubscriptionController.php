<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FamilyStoreGrant;
use App\Models\FamilyStorePlan;
use App\Models\FamilyStorePurchase;
use App\Models\PlatformControl;
use App\Models\ProductiveFamily;
use App\Models\FamilySubscriptionCoupon;
use App\Models\FamilySubscriptionCouponRedemption;
use App\Services\App\FamilySubscriptionCouponService;
use App\Services\App\FamilyStorefrontService;
use App\Services\App\FamilyStorePurchaseService;
use App\Services\App\FamilySubscriptionReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FamilyStoreSubscriptionController extends Controller
{
    public function __construct(
        private readonly FamilyStorefrontService $storefront,
        private readonly FamilyStorePurchaseService $purchasesService,
        private readonly FamilySubscriptionReceiptService $receipts,
    ) {}

    private function owner(Request $request): void
    {
        abort_unless($request->user()?->isPlatformOwner(), 403, 'إدارة باقات الأسر خاصة بمالك المنصة.');
    }

    public function plans(Request $request): JsonResponse
    {
        $this->owner($request);

        return response()->json(['data' => FamilyStorePlan::query()->orderBy('sort_order')->get()]);
    }

    public function updatePlan(Request $request, string $code): JsonResponse
    {
        $this->owner($request);
        $plan = FamilyStorePlan::query()->where('code', $code)->firstOrFail();
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'duration_days' => ['sometimes', 'integer', 'between:1,3650'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_visible' => ['sometimes', 'boolean'],
            'features' => ['sometimes', 'array', 'list', 'max:50'],
            'features.*.id' => ['required_with:features', 'string', 'regex:/^[a-z0-9_]+$/', 'max:60', 'distinct'],
            'features.*.title' => ['required_with:features', 'string', 'max:120'],
            'features.*.description' => ['sometimes', 'nullable', 'string', 'max:400'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.banner' => ['sometimes', 'boolean'],
            'capabilities.themes' => ['sometimes', 'boolean'],
            'capabilities.fonts' => ['sometimes', 'boolean'],
            'capabilities.product_cards' => ['sometimes', 'boolean'],
            'capabilities.rename_store' => ['sometimes', 'boolean'],
            'capabilities.weekly_promotions' => ['sometimes', 'integer', 'between:0,100'],
            'capabilities.coupons' => ['sometimes', 'boolean'],
            'capabilities.max_products' => ['sometimes', 'integer', 'between:0,10000'],
            'capabilities.sales_insights' => ['sometimes', 'boolean'],
            'capabilities.sales_pro' => ['sometimes', 'boolean'],
            'capabilities.featured_products' => ['sometimes', 'integer', 'between:0,100'],
        ]);
        if ($code === 'free') {
            abort_if(isset($validated['price']) && (float) $validated['price'] !== 0.0, 422, 'الباقة المجانية بلا رسوم.');
            abort_if(isset($validated['duration_days']), 422, 'مدة الباقة المجانية غير محددة.');
        } else {
            abort_if(isset($validated['price']) && (float) $validated['price'] < 1.0, 422, 'الحد الأدنى لسعر الباقة ريال واحد.');
        }
        // A feature card is presentation copy; capabilities are the enforced server gates.
        $validated = array_intersect_key($validated, array_flip(['name', 'description', 'price', 'duration_days', 'sort_order', 'is_visible', 'features', 'capabilities']));
        if (isset($validated['capabilities'])) {
            $validated['capabilities'] = array_replace($plan->capabilities ?? [], array_intersect_key($validated['capabilities'], array_flip(['banner', 'themes', 'fonts', 'product_cards', 'rename_store', 'weekly_promotions', 'coupons', 'max_products', 'sales_insights', 'sales_pro', 'featured_products'])));
        }
        $plan->update($validated);

        return response()->json(['data' => $plan->fresh()]);
    }

    public function family(Request $request, ProductiveFamily $family): JsonResponse
    {
        $this->owner($request);

        return response()->json([
            'family_id' => $family->id,
            'entitlement' => $this->storefront->entitlement($family),
            'grants' => FamilyStoreGrant::query()->where('productive_family_id', $family->id)->latest()->limit(50)->get(),
            'purchases' => FamilyStorePurchase::query()->where('productive_family_id', $family->id)
                ->latest()->limit(50)->get(),
        ]);
    }

    public function purchases(Request $request): JsonResponse
    {
        $this->owner($request);

        return response()->json(['data' => FamilyStorePurchase::query()
            ->latest('id')->paginate(30, [
                'id', 'productive_family_id', 'plan_code', 'amount_halalas',
                'currency', 'status', 'given_id', 'provider_payment_id',
                'receipt_number', 'receipt_hash', 'created_at', 'paid_at',
                'mode', 'discount_halalas', 'coupon_code',
            ])]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->owner($request);
        $data = $request->validate(['reference' => ['required', 'string', 'max:100']]);
        $reference = trim($data['reference']);
        $purchase = FamilyStorePurchase::query()->where('receipt_number', $reference)
            ->orWhere('provider_payment_id', $reference)
            ->orWhere('given_id', $reference)
            ->when(ctype_digit($reference), fn ($query) => $query->orWhere('id', (int) $reference))
            ->firstOrFail();
        return response()->json(['data' => $purchase,
            'entitlement' => $this->storefront->entitlement(ProductiveFamily::query()->findOrFail($purchase->productive_family_id))]);
    }

    public function receiptSettings(Request $request): JsonResponse
    {
        $this->owner($request);
        return response()->json(['data' => $this->receipts->settings()]);
    }

    public function updateReceiptSettings(Request $request): JsonResponse
    {
        $this->owner($request);
        $validated = $request->validate([
            'issuer_name' => ['required', 'string', 'max:120'],
            'heading' => ['required', 'string', 'max:120'],
            'intro' => ['required', 'string', 'max:500'],
            'footer' => ['required', 'string', 'max:500'],
            'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'layout' => ['required', 'in:classic,compact'],
        ]);
        $settings = array_replace($this->receipts->settings(), $validated);
        PlatformControl::query()->updateOrCreate(['section' => 'family_subscription_receipt'], [
            'value' => $settings, 'updated_by' => $request->user()->id,
            'description' => 'New family subscription receipt wording',
        ]);
        return response()->json(['data' => $this->receipts->settings()]);
    }

    public function uploadReceiptImage(Request $request, string $kind): JsonResponse
    {
        $this->owner($request);
        abort_unless(in_array($kind, ['logo', 'image', 'stamp'], true), 404);
        $data = $request->validate(['image' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg', 'max:2048']]);
        $file = $data['image'];
        $bytes = file_get_contents($file->getRealPath());
        abort_unless(is_string($bytes) && $bytes !== '', 422);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless(in_array($mime, ['image/png', 'image/jpeg'], true), 422);
        $path = 'family-subscription-receipt-assets/'.Str::uuid().($mime === 'image/png' ? '.png' : '.jpg');
        abort_unless(Storage::disk('r2_contracts')->put($path, $bytes), 503, 'تعذر حفظ صورة الإيصال.');
        $settings = $this->receipts->settings();
        $settings[$kind.'_path'] = $path;
        PlatformControl::query()->updateOrCreate(['section' => 'family_subscription_receipt'], [
            'value' => $settings, 'updated_by' => $request->user()->id,
            'description' => 'New family subscription receipt wording and layout',
        ]);
        return response()->json(['data' => $this->receipts->settings()]);
    }

    public function receiptImage(Request $request, string $kind): JsonResponse
    {
        $this->owner($request);
        abort_unless(in_array($kind, ['logo', 'image', 'stamp'], true), 404);
        $path = $this->receipts->settings()[$kind.'_path'] ?? null;
        abort_unless(is_string($path) && $path !== '', 404);
        $bytes = Storage::disk('r2_contracts')->get($path);
        abort_unless(is_string($bytes) && $bytes !== '', 404);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless(in_array($mime, ['image/png', 'image/jpeg'], true), 500);
        return response()->json(['mime' => $mime, 'image_base64' => base64_encode($bytes)]);
    }

    public function verifyPurchase(Request $request, FamilyStorePurchase $purchase): JsonResponse
    {
        $this->owner($request);
        $data = $request->validate(['payment_id' => ['sometimes', 'uuid']]);
        $purchase = $this->purchasesService->reconcile($purchase, $data['payment_id'] ?? null);
        return response()->json(['data' => $purchase]);
    }

    public function receipt(Request $request, FamilyStorePurchase $purchase): JsonResponse
    {
        $this->owner($request);
        abort_unless($purchase->status === 'paid', 404);
        $bytes = $this->receipts->pdf($purchase);
        return response()->json(['number' => $purchase->receipt_number,
            'sha256' => hash('sha256', $bytes), 'pdf_base64' => base64_encode($bytes)]);
    }

    public function grant(Request $request, ProductiveFamily $family): JsonResponse
    {
        $this->owner($request);
        $data = $request->validate([
            'plan' => ['required', 'string', 'in:silver,gold'],
            'duration_unit' => ['sometimes', 'string', 'in:hours,days'],
            'duration_value' => ['required_with:duration_unit', 'integer', 'min:1'],
            'days' => ['required_without:duration_unit', 'integer', 'between:1,3650'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $unit = $data['duration_unit'] ?? 'days';
        $value = (int) ($data['duration_value'] ?? $data['days']);
        abort_unless($unit === 'hours' ? $value <= 24 * 3650 : $value <= 3650, 422, 'المدة أطول من المسموح.');
        abort_unless(FamilyStorePlan::query()->where('code', $data['plan'])->exists(), 422);

        $grant = DB::transaction(function () use ($family, $data, $request, $unit, $value): FamilyStoreGrant {
            ProductiveFamily::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
            // A new gift replaces the previous gift; paid plans are never touched.
            FamilyStoreGrant::query()->where('productive_family_id', $family->id)
                ->where('source', 'owner_grant')->whereNull('revoked_at')->where('ends_at', '>', now())
                ->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id,
                    'revoked_by_role' => 'owner', 'revocation_reason' => 'Replaced by a new gift']);
            $start = $this->storefront->nextStart($family->id, $data['plan']);

            return FamilyStoreGrant::query()->create([
                'productive_family_id' => $family->id,
                'plan_code' => $data['plan'],
                'source' => 'owner_grant',
                'starts_at' => $start,
                'ends_at' => $unit === 'hours' ? $start->copy()->addHours($value) : $start->copy()->addDays($value),
                'granted_by' => $request->user()->id,
                'reason' => $data['reason'] ?? null,
            ]);
        });

        return response()->json(['data' => $grant, 'entitlement' => $this->storefront->entitlement($family)], 201);
    }

    public function revoke(Request $request, ProductiveFamily $family, FamilyStoreGrant $grant): JsonResponse
    {
        $this->owner($request);
        abort_unless($grant->productive_family_id === $family->id, 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $reason = trim((string) ($data['reason'] ?? ''));
        // Cancelling a paid plan is allowed; the refund itself is done in Moyasar and noted here.
        abort_if($grant->source === 'purchase' && $reason === '', 422, 'اكتب سبب إلغاء الاشتراك المدفوع (مثلاً: تم الاسترداد من ميسر).');
        DB::transaction(function () use ($grant, $request, $reason): void {
            ProductiveFamily::query()->whereKey($grant->productive_family_id)->lockForUpdate()->firstOrFail();
            $grant->refresh();
            if (! $grant->revoked_at) {
                $remainingSeconds = max(0, $grant->ends_at->timestamp - max(now()->timestamp, $grant->starts_at->timestamp));
                if ($remainingSeconds > 0) {
                    FamilyStoreGrant::query()->where('productive_family_id', $grant->productive_family_id)
                        ->where('source', 'purchase')->whereNull('revoked_at')
                        ->where('starts_at', '>=', $grant->ends_at)
                        ->orderBy('starts_at')->lockForUpdate()->get()
                        ->each(function (FamilyStoreGrant $queued) use ($remainingSeconds): void {
                            $queued->update([
                                'starts_at' => $queued->starts_at->copy()->subSeconds($remainingSeconds),
                                'ends_at' => $queued->ends_at->copy()->subSeconds($remainingSeconds),
                            ]);
                        });
                }
                $grant->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id,
                    'revoked_by_role' => 'owner', 'revocation_reason' => $reason !== '' ? $reason : null]);
            }
        });

        return response()->json(['data' => $grant->fresh(), 'entitlement' => $this->storefront->entitlement($family)]);
    }

    /** Families registered on the platform, searchable, for gifts and support. */
    public function families(Request $request): JsonResponse
    {
        $this->owner($request);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $search = trim((string) ($data['search'] ?? ''));
        $page = ProductiveFamily::query()
            ->with('store:id,productive_family_id,name_ar,logo_path,status')
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                $query->where(function ($inner) use ($like, $search): void {
                    $inner->where('owner_name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        ->orWhereHas('store', fn ($store) => $store->where('name_ar', 'like', $like));
                    if (ctype_digit($search)) {
                        $inner->orWhere('id', (int) $search);
                    }
                });
            })
            ->orderBy('owner_name')
            ->paginate(20, ['id', 'code', 'owner_name', 'phone', 'status']);

        $page->getCollection()->transform(function (ProductiveFamily $family): array {
            $entitlement = $this->storefront->entitlement($family);

            return [
                'id' => $family->id,
                'code' => $family->code,
                'owner_name' => $family->owner_name,
                'phone' => $family->phone,
                'status' => $family->status,
                'store_name' => $family->store?->name_ar,
                'plan' => $entitlement['plan'],
                'plan_source' => $entitlement['source'],
                'plan_ends_at' => $entitlement['ends_at'],
            ];
        });

        return response()->json(['data' => $page]);
    }

    /** Stops every active and queued plan of one family (gifts and paid). */
    public function cancelFamily(Request $request, ProductiveFamily $family): JsonResponse
    {
        $this->owner($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $count = DB::transaction(function () use ($family, $request, $data): int {
            ProductiveFamily::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();

            return $this->storefront->cancelAll($family->id, 'owner', $request->user()->id, $data['reason']);
        });

        return response()->json(['cancelled' => $count, 'entitlement' => $this->storefront->entitlement($family)]);
    }

    public function coupons(Request $request): JsonResponse
    {
        $this->owner($request);

        return response()->json(['data' => FamilySubscriptionCoupon::query()
            ->withCount([
                'redemptions as reserved_count' => fn ($q) => $q->where('status', 'reserved'),
            ])
            ->latest('id')->limit(200)->get()]);
    }

    public function storeCoupon(Request $request): JsonResponse
    {
        $this->owner($request);
        $data = $this->validatedCoupon($request, true);
        $data['code'] = FamilySubscriptionCouponService::normalize($data['code']);
        abort_if(FamilySubscriptionCoupon::query()->where('code', $data['code'])->exists(), 422, 'كود الخصم مستخدم مسبقاً.');
        $coupon = FamilySubscriptionCoupon::query()->create([...$data, 'created_by' => $request->user()->id]);

        return response()->json(['data' => $coupon], 201);
    }

    public function updateCoupon(Request $request, FamilySubscriptionCoupon $coupon): JsonResponse
    {
        $this->owner($request);
        $data = $this->validatedCoupon($request, false);
        unset($data['code']); // A code that families already received never changes.
        $coupon->update($data);

        return response()->json(['data' => $coupon->fresh()]);
    }

    public function couponRedemptions(Request $request, FamilySubscriptionCoupon $coupon): JsonResponse
    {
        $this->owner($request);

        return response()->json(['data' => FamilySubscriptionCouponRedemption::query()
            ->where('coupon_id', $coupon->id)->latest('id')->limit(200)->get()
            ->map(fn (FamilySubscriptionCouponRedemption $r): array => [
                'id' => $r->id, 'purchase_id' => $r->purchase_id, 'status' => $r->status,
                'discount_halalas' => $r->discount_halalas, 'created_at' => $r->created_at?->toISOString(),
                'family_id' => $r->productive_family_id,
                'family_name' => ProductiveFamily::query()->find($r->productive_family_id)?->owner_name,
            ])]);
    }

    private function validatedCoupon(Request $request, bool $creating): array
    {
        $rule = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'code' => [$rule, 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'discount_type' => [$rule, 'in:percentage,fixed'],
            'discount_value' => [$rule, 'numeric', 'gt:0', 'max:100000'],
            'plan_codes' => ['sometimes', 'nullable', 'array'],
            'plan_codes.*' => ['in:silver,gold'],
            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_family_limit' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (($data['discount_type'] ?? null) === 'percentage') {
            abort_if((float) $data['discount_value'] > 100, 422, 'نسبة الخصم لا تتجاوز 100%.');
        }
        if (! empty($data['starts_at']) && ! empty($data['ends_at'])) {
            abort_if(strtotime($data['ends_at']) <= strtotime($data['starts_at']), 422, 'تاريخ النهاية يجب أن يكون بعد البداية.');
        }
        if (array_key_exists('plan_codes', $data) && $data['plan_codes'] === []) {
            $data['plan_codes'] = null;
        }

        return $data;
    }
}
