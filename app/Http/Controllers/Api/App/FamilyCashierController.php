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
        private readonly \App\Services\App\CashierStaffService $staffService,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $trialStarted = null;
        if ($this->ctx($request)->isOwner() && $this->ctx($request)->device === null && $store->productiveFamily) {
            $trialStarted = $this->cashier->startTrialIfEligible($store->productiveFamily);
        }
        $entitlement = $this->cashier->entitlement($store->productiveFamily);
        $settings = $this->cashier->settings();

        return response()->json(['data' => $entitlement + [
            'feature_labels' => array_map(fn ($f) => $f['label'], $settings['features']),
            'pro_features' => array_keys(array_filter($settings['features'], fn ($f) => $f['pro'] && ! $f['basic'])),
            'plan_name' => $settings['name'],
            'description' => $settings['description'],
            'today' => $this->dayTotals($store, now(self::TZ)),
            'me' => CashierStaffController::me($this->ctx($request)),
            'shift' => ($shift = $this->staffService->openShift($this->ctx($request))) ? $this->staffService->shiftPayload($shift) : null,
            'staff_enabled' => $this->staffService->ready() && $this->staffService->enabled($store),
            'tax' => app(\App\Services\App\StoreTaxService::class)->payload($store),
            'trial_started' => $trialStarted ? ['days' => $this->cashier->settings()['trial_days'], 'ends_at' => $trialStarted->ends_at->toISOString()] : null,
        ]]);
    }

    /** The store's VAT details, the same record as in the store details page. */
    public function tax(Request $request): JsonResponse
    {
        return response()->json(['data' => app(\App\Services\App\StoreTaxService::class)->payload($this->store($request))]);
    }

    public function saveTax(Request $request): JsonResponse
    {
        abort_unless($this->ctx($request)->isOwner(), 403, 'هذه للمالكة فقط.');
        $tax = app(\App\Services\App\StoreTaxService::class);
        $data = $request->validate($tax->rules(), ['vat_number.regex' => 'الرقم الضريبي ١٥ رقم، يبدأ بـ 3 وينتهي بـ 3.']);
        $store = $this->store($request);
        $tax->save($store, $data['trade_name'] ?? null, $data['vat_number'] ?? null);

        return response()->json(['message' => 'تم حفظ البيانات الضريبية.', 'data' => $tax->payload($store->refresh())]);
    }

    /** One launch offer per family, when the owner switched it on for its plan. */
    public function claimLaunchOffer(Request $request): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($this->ctx($request)->isOwner(), 403, 'هذه للمالكة فقط.');
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
        $ctx = $this->ctx($request);
        abort_unless($ctx->can('sell'), 403, 'ما عندك صلاحية البيع.');
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
            'vat' => ['sometimes', 'boolean'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_vat_number' => ['nullable', 'string', 'regex:'.\App\Services\App\StoreTaxService::NUMBER_REGEX],
        ], ['customer_vat_number.regex' => 'رقم العميل الضريبي ١٥ رقم، يبدأ بـ 3 وينتهي بـ 3.']);
        $ids = collect($data['items'])->pluck('product_id')->filter()->unique()->values();
        $products = Product::query()->where('store_id', $store->id)->whereIn('id', $ids)->get()->keyBy('id');
        abort_unless($products->count() === $ids->count(), 422, 'منتج غير موجود في متجرك.');
        $customItems = collect($data['items'])->filter(fn ($i) => empty($i['product_id']));
        if ($customItems->isNotEmpty()) {
            abort_unless($this->cashier->entitlement($store->productiveFamily)['tier'] === 'pro', 422,
                'أضيفي المنتج في متجرك على زاد سينك عشان يطلع لك هنا.');
        }
        foreach (collect($data['items'])->groupBy('product_id') as $productId => $lines) {
            $product = $productId ? $products->get((int) $productId) : null;
            $wanted = (int) $lines->sum('quantity');
            if ($product && $product->track_stock && $wanted > (int) $product->stock_quantity) {
                abort(422, (int) $product->stock_quantity > 0
                    ? 'باقي '.(int) $product->stock_quantity.' فقط من '.$product->name_ar.'.'
                    : 'نفذت كمية '.$product->name_ar.'.');
            }
        }
        $items = collect($data['items'])->map(function (array $i) use ($products): array {
            $product = isset($i['product_id']) ? $products->get($i['product_id']) : null;

            return [
                'product_id' => $product?->id,
                'name' => $product ? (string) $product->name_ar : trim((string) $i['name']),
                'quantity' => (int) $i['quantity'],
                'unit_price' => $product ? (float) $product->price : (float) $i['unit_price'],
            ];
        })->all();
        $discount = (float) ($data['discount'] ?? 0);
        if ($discount > 0 && ! $ctx->isOwner()) {
            abort_unless($ctx->can('discount'), 403, 'ما عندك صلاحية الخصم.');
            $subtotal = array_sum(array_map(fn ($i) => $i['quantity'] * $i['unit_price'], $items));
            $max = (int) ($ctx->staff?->max_discount_percent ?? 0);
            abort_if($max > 0 && $subtotal > 0 && $discount > $subtotal * $max / 100 + 0.001, 422, "أقصى خصم لك {$max}٪.");
        }
        $shift = $this->staffService->openShift($ctx);
        abort_if($shift === null && ! $ctx->isOwner(), 422, 'افتحي الوردية أول.');
        $receipt = $this->receipts->issueQuickSale($store, $items, $data['payment_method'], $data['channel'],
            $data['customer_phone'] ?? null, $discount, $ctx->staff ? [
                'cashier_staff_id' => $ctx->staff->id,
                'cashier_shift_id' => $shift?->id,
                'staff_name' => $ctx->staff->name,
                'cashier_device_id' => $ctx->device?->id,
            ] : [], [
                'vat' => (bool) ($data['vat'] ?? false),
                'customer_name' => $data['customer_name'] ?? null,
                'customer_vat_number' => $data['customer_vat_number'] ?? null,
            ]);

        return response()->json(['message' => 'تم حفظ البيع وإصدار الإيصال.', 'data' => $this->receipts->payload($receipt)], 201);
    }

    /** The store's products for the cashier, for whoever is using it (owner or employee). */
    public function products(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $this->requireFeature($store, 'quick_sale');
        $products = Product::query()->where('store_id', $store->id)->whereNull('deleted_at')
            ->where('status', '!=', 'archived')->latest()->get();

        return response()->json(['data' => $products->map(function (Product $p): array {
            $first = is_array($p->images) ? ($p->images[0] ?? null) : null;

            return [
                'id' => $p->id,
                'name_ar' => $p->name_ar,
                'price' => (float) $p->price,
                'status' => $p->status,
                'is_available' => (bool) $p->is_available,
                'cashier_only' => (bool) ($p->cashier_only ?? false),
                'track_stock' => (bool) ($p->track_stock ?? false),
                'stock_quantity' => $p->track_stock ? (int) $p->stock_quantity : null,
                'images' => is_array($p->images) ? $p->images : [],
                'image_url' => is_string($first) && $first !== ''
                    ? (filter_var($first, FILTER_VALIDATE_URL) !== false ? $first : \Illuminate\Support\Facades\Storage::disk('public')->url(ltrim($first, '/')))
                    : null,
            ];
        })->values()]);
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
        abort_unless($this->ctx($request)->can('reports'), 403, 'ما عندك صلاحية التقارير.');
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? null;
        $day = $date ? Carbon::parse($date, self::TZ) : now(self::TZ);

        return response()->json(['data' => $this->dayTotals($store, $day) + ['date' => $day->toDateString()]]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $this->requireFeature($store, 'inventory');

        $ctx = $this->ctx($request);
        abort_unless($ctx->can('inventory') || $ctx->can('sell'), 403, 'ما عندك صلاحية المخزون.');
        $snapshot = app(\App\Services\App\CashierInventoryService::class)->snapshot($store);
        if (! $ctx->can('reports')) {
            $snapshot['products'] = array_map(fn ($p) => array_diff_key($p, array_flip(['unit_cost', 'profit', 'recipe'])), (array) $snapshot['products']);
            $snapshot['materials'] = array_map(fn ($m) => array_diff_key($m, array_flip(['unit_cost', 'value'])), (array) $snapshot['materials']);
        }

        return response()->json(['data' => $snapshot + [
            'features' => $this->cashier->entitlement($store->productiveFamily)['features'],
        ]]);
    }

    /** Stock of one product: track it, set the quantity, or keep it in the cashier only. */
    public function updateStock(Request $request, Product $product): JsonResponse
    {
        $store = $this->store($request);
        $this->requireFeature($store, 'inventory');
        abort_unless($product->store_id === $store->id, 404);
        $data = $request->validate([
            'track_stock' => ['sometimes', 'boolean'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'cashier_only' => ['sometimes', 'boolean'],
        ]);
        $inventory = app(\App\Services\App\CashierInventoryService::class);
        DB::transaction(function () use ($data, $product, $store, $inventory): void {
            Product::query()->whereKey($product->id)->lockForUpdate()->first();
            if (array_key_exists('cashier_only', $data) && $data['cashier_only'] !== (bool) $product->cashier_only) {
                $this->checkCatalogLimits($store, $data['cashier_only']);
                $product->forceFill(['cashier_only' => $data['cashier_only']])->save();
            }
            if (array_key_exists('track_stock', $data)) {
                $product->forceFill(['track_stock' => $data['track_stock'], 'stock_quantity' => $data['track_stock'] ? (int) ($product->stock_quantity ?? 0) : null])->save();
            }
            if (array_key_exists('stock_quantity', $data)) {
                if (! $product->track_stock) {
                    $product->forceFill(['track_stock' => true, 'stock_quantity' => 0])->save();
                }
                $inventory->moveProduct($product->id, $data['stock_quantity'] - (int) $product->fresh()->stock_quantity, 'adjust', 'manual');
            }
        });

        return response()->json(['message' => 'تم تحديث المخزون.', 'data' => collect($inventory->snapshot($store)['products'])->firstWhere('id', $product->id)]);
    }

    /** A product moving between the online store and the cashier respects both limits. */
    public function checkCatalogLimits(Store $store, bool $toCashierOnly): void
    {
        if ($toCashierOnly) {
            $limit = $this->cashier->settings()['cashier_only_limit'];
            $count = Product::query()->where('store_id', $store->id)->where('cashier_only', true)->count();
            abort_if($count >= $limit, 422, "وصلتِ لحد منتجات الكاشير ({$limit}).");

            return;
        }
        $platform = (int) app(\App\Services\App\PlatformOperationGate::class)->number('products_categories', 'maximumProductsPerStore', 10);
        $limit = app(\App\Services\App\FamilyStorefrontService::class)->productLimit($store, $platform);
        $count = Product::query()->where('store_id', $store->id)->onlineVisible()->count();
        abort_if($limit > 0 && $count >= $limit, 422, "باقتك تسمح بـ {$limit} منتجات في المتجر أونلاين. رقّي باقتك لعرض منتجات أكثر.");
    }

    public function saveMaterial(Request $request, ?\App\Models\CashierMaterial $material = null): JsonResponse
    {
        $store = $this->store($request);
        $this->requireFeature($store, 'materials');
        abort_if($material && $material->store_id !== $store->id, 404);
        $data = $request->validate([
            'name' => [$material ? 'sometimes' : 'required', 'string', 'max:80'],
            'kind' => ['sometimes', 'in:raw,packaging'],
            'unit' => [$material ? 'sometimes' : 'required', 'string', 'max:30'],
            'quantity' => ['sometimes', 'numeric', 'min:0', 'max:10000000'],
            'add_quantity' => ['sometimes', 'numeric', 'min:0', 'max:10000000'],
            'unit_cost' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'low_alert' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);
        $inventory = app(\App\Services\App\CashierInventoryService::class);
        $material = DB::transaction(function () use ($data, $material, $store, $inventory): \App\Models\CashierMaterial {
            if (! $material) {
                abort_if(\App\Models\CashierMaterial::query()->where('store_id', $store->id)->count() >= 500, 422, 'وصلتِ للحد الأقصى للمواد.');
                $material = \App\Models\CashierMaterial::query()->create([
                    'store_id' => $store->id, 'name' => $data['name'], 'unit' => $data['unit'],
                    'kind' => $data['kind'] ?? 'raw', 'unit_cost' => $data['unit_cost'] ?? 0, 'low_alert' => $data['low_alert'] ?? null,
                ]);
                $inventory->moveMaterial($material, (float) ($data['quantity'] ?? 0), 'purchase', 'new');

                return $material->fresh();
            }
            $material = \App\Models\CashierMaterial::query()->whereKey($material->id)->lockForUpdate()->firstOrFail();
            $material->fill(array_intersect_key($data, array_flip(['name', 'kind', 'unit', 'unit_cost', 'low_alert'])))->save();
            if (isset($data['add_quantity'])) {
                $inventory->moveMaterial($material, (float) $data['add_quantity'], 'purchase', 'restock');
            } elseif (isset($data['quantity'])) {
                $inventory->moveMaterial($material, (float) $data['quantity'] - $material->quantity, 'adjust', 'manual');
            }

            return $material->fresh();
        });

        return response()->json(['message' => 'تم حفظ المادة.', 'data' => $material], $request->isMethod('post') ? 201 : 200);
    }

    public function deleteMaterial(Request $request, \App\Models\CashierMaterial $material): JsonResponse
    {
        $store = $this->store($request);
        $this->requireFeature($store, 'materials');
        abort_unless($material->store_id === $store->id, 404);
        \App\Models\CashierRecipeItem::query()->where('material_id', $material->id)->delete();
        $material->delete();

        return response()->json(['message' => 'تم حذف المادة.']);
    }

    /** Recipe: per material, either how much one product uses, or how many products one unit makes. */
    public function saveRecipe(Request $request, Product $product): JsonResponse
    {
        $store = $this->store($request);
        $this->requireFeature($store, 'recipes');
        abort_unless($product->store_id === $store->id, 404);
        $data = $request->validate([
            'items' => ['present', 'array', 'max:30'],
            'items.*.material_id' => ['required', 'integer'],
            'items.*.per_unit' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'items.*.yield' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
        ]);
        $ids = collect($data['items'])->pluck('material_id')->unique();
        abort_unless(\App\Models\CashierMaterial::query()->where('store_id', $store->id)->whereIn('id', $ids)->count() === $ids->count(), 422, 'مادة غير موجودة.');
        DB::transaction(function () use ($data, $product): void {
            \App\Models\CashierRecipeItem::query()->where('product_id', $product->id)->delete();
            foreach ($data['items'] as $item) {
                $perUnit = isset($item['per_unit']) ? (float) $item['per_unit'] : (isset($item['yield']) ? 1 / (float) $item['yield'] : null);
                abort_if($perUnit === null, 422, 'حددي الكمية لكل مادة.');
                \App\Models\CashierRecipeItem::query()->updateOrCreate(
                    ['product_id' => $product->id, 'material_id' => $item['material_id']],
                    ['per_unit' => round($perUnit, 5)],
                );
            }
        });
        $snapshot = app(\App\Services\App\CashierInventoryService::class)->snapshot($store);

        return response()->json(['message' => 'تم حفظ الوصفة.', 'data' => collect($snapshot['products'])->firstWhere('id', $product->id)]);
    }

    public function produce(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $this->requireFeature($store, 'recipes');
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
        $product = Product::query()->where('store_id', $store->id)->findOrFail($data['product_id']);
        $production = app(\App\Services\App\CashierInventoryService::class)->produce($store, $product, $data['quantity'], $request->user()->id);
        $short = collect($production->materials)->where('short', true)->pluck('name');

        return response()->json([
            'message' => $short->isEmpty() ? 'تم تسجيل الإنتاج.' : 'تم تسجيل الإنتاج، وبعض المواد ما كانت كافية: '.$short->implode('، '),
            'data' => $production,
        ], 201);
    }

    /** Inventory report: products, materials and the last productions, as xlsx, pdf or doc. */
    public function exportInventory(Request $request): \Illuminate\Http\Response
    {
        $store = $this->store($request);
        $this->requireFeature($store, 'reports');
        $format = (string) $request->validate(['format' => ['required', 'in:xlsx,pdf,doc']])['format'];
        $snap = app(\App\Services\App\CashierInventoryService::class)->snapshot($store);
        $productRows = array_map(fn ($p) => [$p['name'], $p['cashier_only'] ? 'كاشير فقط' : 'المتجر والكاشير',
            $p['stock'] ?? '—', $p['price'], $p['unit_cost'] ?? '—', $p['profit'] ?? '—'], $snap['products']);
        $materialRows = array_map(fn ($m) => [$m['name'], $m['kind'] === 'packaging' ? 'تغليف' : 'مادة خام',
            $m['quantity'].' '.$m['unit'], $m['unit_cost'], $m['value'], $m['low'] ? 'قرب يخلص' : ''], $snap['materials']);
        $sections = [
            ['المنتجات', ['المنتج', 'العرض', 'الكمية', 'السعر', 'التكلفة', 'الربح'], $productRows],
            ['المواد الخام والتغليف', ['المادة', 'النوع', 'الكمية', 'سعر الوحدة', 'القيمة', 'تنبيه'], $materialRows],
        ];
        $name = 'inventory-'.now(self::TZ)->format('Y-m-d');

        if ($format === 'xlsx') {
            $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            foreach ($sections as $i => [$title, $headers, $rows]) {
                $sheet = $i === 0 ? $book->getActiveSheet() : $book->createSheet();
                $sheet->setTitle($title === 'المنتجات' ? 'المنتجات' : 'المواد')->setRightToLeft(true);
                $sheet->fromArray($headers, null, 'A1');
                $sheet->fromArray($rows, null, 'A2');
                $sheet->getStyle('A1:F1')->getFont()->setBold(true);
                foreach (range('A', 'F') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
            }
            ob_start();
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');

            return response((string) ob_get_clean(), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$name.'.xlsx"',
            ]);
        }
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = '<html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>body{font-family:'.($format === 'doc' ? 'Arial' : 'sans-serif')
            .';color:#102B4E;font-size:10pt}h1{color:#FF7900;font-size:16pt}h2{font-size:13pt;margin-top:16px}table{width:100%;border-collapse:collapse}'
            .'th{background:#102B4E;color:#fff;padding:6px}td{border-bottom:1px solid #E6E9EE;padding:5px}</style></head><body dir="rtl">'
            .'<h1>تقرير المخزون — '.$e($store->name_ar).'</h1><p>'.$e(now(self::TZ)->format('Y/m/d h:i A')).' — كاشير زاد</p>';
        foreach ($sections as [$title, $headers, $rows]) {
            $html .= '<h2>'.$e($title).'</h2><table><tr>'.implode('', array_map(fn ($h) => '<th>'.$e($h).'</th>', $headers)).'</tr>'
                .implode('', array_map(fn ($r) => '<tr>'.implode('', array_map(fn ($c) => '<td>'.$e($c).'</td>', $r)).'</tr>', $rows)).'</table>';
        }
        $html .= '</body></html>';
        if ($format === 'doc') {
            return response($html, 200, ['Content-Type' => 'application/msword; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'.doc"']);
        }
        $temp = storage_path('app/private/sale-receipt-mpdf');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($temp);
        $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $temp, 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $pdf->SetDirectionality('rtl');
        $pdf->WriteHTML($html);

        return response($pdf->Output('', 'S'), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"']);
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

    private function ctx(Request $request): \App\Services\App\CashierContext
    {
        return $this->staffService->resolve($request);
    }

    private function requireFeature(Store $store, string $feature): void
    {
        $request = request();
        if (in_array($request->route()?->getActionMethod(), ['updateStock', 'saveRecipe', 'saveMaterial', 'deleteMaterial', 'produce', 'exportInventory'], true)) {
            abort_unless($this->ctx($request)->can('inventory'), 403, 'ما عندك صلاحية المخزون.');
        }
        $features = $this->cashier->entitlement($store->productiveFamily)['features'];
        abort_unless(! empty($features[$feature]), 403, 'هذه الميزة تتطلب كاشير زاد (الباقة الذهبية أو كاشير برو).');
    }

    private function store(Request $request): Store
    {
        return $this->ctx($request)->store;
    }
}
