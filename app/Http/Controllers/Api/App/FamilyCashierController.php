<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Product;
use App\Models\SaleReceipt;
use App\Models\Store;
use App\Services\App\CashierZadService;
use App\Services\SaleReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The family's own cashier. Direct sales are paid to the family (cash, network,
 * transfer…); Zad Sync takes no commission on them and only issues the receipt.
 */
class FamilyCashierController extends Controller
{
    private const TZ = 'Asia/Riyadh';

    public function __construct(
        private readonly CashierZadService $cashier,
        private readonly SaleReceiptService $receipts,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $entitlement = $this->cashier->entitlement($store->productiveFamily);
        $settings = $this->cashier->settings();

        return response()->json(['data' => $entitlement + [
            'feature_labels' => array_map(fn ($f) => $f['label'], $settings['features']),
            'pro_features' => array_keys(array_filter($settings['features'], fn ($f) => $f['pro'] && ! $f['basic'])),
            'plan_name' => $settings['name'],
            'description' => $settings['description'],
            'today' => $this->dayTotals($store, now(self::TZ)),
        ]]);
    }

    /** One launch offer per family, when the owner switched it on for its plan. */
    public function claimLaunchOffer(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $result = DB::transaction(function () use ($store): array {
            $family = \App\Models\ProductiveFamily::query()->whereKey($store->productive_family_id)->lockForUpdate()->firstOrFail();
            $plan = app(\App\Services\App\FamilyStorefrontService::class)->catalogEntitlement($family)['plan'] ?? 'free';
            $offer = $this->cashier->launchOfferFor($family, $plan);
            abort_unless($offer !== null, 422, 'العرض غير متاح لحسابك.');
            $grant = $this->cashier->addTime($family->id, $offer['days'] * 86400, 'launch_offer', 'عرض الإطلاق');

            return ['ends_at' => $grant->ends_at->toISOString(), 'days' => $offer['days']];
        });

        return response()->json(['message' => 'تم تفعيل كاشير زاد برو.', 'data' => $result]);
    }

    public function sell(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $this->requireFeature($store, 'quick_sale');
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.name' => ['required_without:items.*.product_id', 'nullable', 'string', 'max:120'],
            'items.*.unit_price' => ['required_without:items.*.product_id', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'payment_method' => ['required', 'in:cash,network,transfer,apple_pay,other'],
            'channel' => ['required', 'in:door,shop,bazaar'],
            'customer_phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s-]{9,15}$/'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
        $ids = collect($data['items'])->pluck('product_id')->filter()->unique()->values();
        $products = Product::query()->where('store_id', $store->id)->whereIn('id', $ids)->get()->keyBy('id');
        abort_unless($products->count() === $ids->count(), 422, 'منتج غير موجود في متجرك.');
        $items = collect($data['items'])->map(function (array $i) use ($products): array {
            $product = isset($i['product_id']) ? $products->get($i['product_id']) : null;

            return [
                'product_id' => $product?->id,
                'name' => $product ? (string) $product->name_ar : trim((string) $i['name']),
                'quantity' => (int) $i['quantity'],
                'unit_price' => $product ? (float) $product->price : (float) $i['unit_price'],
            ];
        })->all();
        $receipt = $this->receipts->issueQuickSale($store, $items, $data['payment_method'], $data['channel'],
            $data['customer_phone'] ?? null, (float) ($data['discount'] ?? 0));

        return response()->json(['message' => 'تم حفظ البيع وإصدار الإيصال.', 'data' => $this->receipts->payload($receipt)], 201);
    }

    /** The newest direct sale for the customer-facing screen (QR of the receipt). */
    public function display(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $receipt = SaleReceipt::query()->where('store_id', $store->id)->where('sale_type', SaleReceipt::TYPE_QUICK)
            ->where('issued_at', '>=', now()->subMinutes(3))->latest('id')->first();

        return response()->json(['data' => $receipt ? [
            'id' => $receipt->id,
            'number' => $receipt->number,
            'total' => (float) $receipt->total,
            'items' => $receipt->items ?? [],
            'url' => $this->receipts->publicUrl($receipt),
            'issued_at' => $receipt->issued_at?->toISOString(),
            'store_name' => $receipt->store_name,
        ] : null]);
    }

    public function day(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $this->requireFeature($store, 'day_close');
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? null;
        $day = $date ? Carbon::parse($date, self::TZ) : now(self::TZ);

        return response()->json(['data' => $this->dayTotals($store, $day) + ['date' => $day->toDateString()]]);
    }

    private function dayTotals(Store $store, Carbon $day): array
    {
        $receipts = SaleReceipt::query()->where('store_id', $store->id)->where('status', 'paid')
            ->whereBetween('issued_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()])
            ->get(['sale_type', 'payment_method', 'total']);
        $direct = $receipts->where('sale_type', '!=', SaleReceipt::TYPE_PLATFORM);
        $methods = [];
        foreach ($direct->groupBy('payment_method') as $method => $group) {
            $methods[] = [
                'method' => $method,
                'label' => SaleReceiptService::METHOD_LABELS[$method] ?? $method,
                'count' => $group->count(),
                'total' => round((float) $group->sum('total'), 2),
            ];
        }

        return [
            'direct_total' => round((float) $direct->sum('total'), 2),
            'direct_count' => $direct->count(),
            'online_total' => round((float) $receipts->where('sale_type', SaleReceipt::TYPE_PLATFORM)->sum('total'), 2),
            'online_count' => $receipts->where('sale_type', SaleReceipt::TYPE_PLATFORM)->count(),
            'methods' => $methods,
        ];
    }

    private function requireFeature(Store $store, string $feature): void
    {
        $features = $this->cashier->entitlement($store->productiveFamily)['features'];
        abort_unless(! empty($features[$feature]), 403, 'هذه الميزة تتطلب كاشير زاد (الباقة الذهبية أو كاشير برو).');
    }

    private function store(Request $request): Store
    {
        $profile = AppProfile::query()->where('user_id', $request->user()->id)->first();
        abort_unless($profile?->productive_family_id !== null, 403, 'هذا الحساب غير مرتبط بأسرة منتجة.');
        $store = Store::query()->with('productiveFamily')->where('productive_family_id', $profile->productive_family_id)->first();
        abort_unless($store !== null, 404, 'لم يتم العثور على متجر الأسرة.');

        return $store;
    }
}
