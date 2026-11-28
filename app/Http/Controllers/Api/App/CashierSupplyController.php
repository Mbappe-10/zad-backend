<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\CashierBranch;
use App\Models\CashierDevice;
use App\Models\CashierMaterial;
use App\Models\CashierPurchase;
use App\Models\CashierSupplier;
use App\Models\CashierTransfer;
use App\Models\Store;
use App\Services\App\CashierContext;
use App\Services\App\CashierInventoryService;
use App\Services\App\CashierStaffService;
use App\Services\App\CashierSupplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner board for suppliers, purchase invoices, branches and transfers (Cashier Zad Pro).
 * Everything needs the inventory permission; money (payments, balances) needs reports too.
 */
class CashierSupplyController extends Controller
{
    public function __construct(
        private readonly CashierSupplyService $supply,
        private readonly CashierStaffService $staff,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $data = $this->supply->overview($store);
        if (! $this->ctx($request)->can('reports')) {
            unset($data['totals']['owed'], $data['totals']['overdue'], $data['totals']['purchased_this_month']);
            $data['suppliers'] = collect($data['suppliers'])->map(fn ($s) => array_diff_key($s, array_flip(['purchased', 'balance', 'overdue'])))->values();
        }

        return response()->json(['data' => $data + ['devices' => $this->supply->devices($store)]]);
    }

    // ------------------------------------------------------------ suppliers

    public function saveSupplier(Request $request, ?CashierSupplier $supplier = null): JsonResponse
    {
        $store = $this->store($request);
        abort_if($supplier && $supplier->store_id !== $store->id, 404);
        $data = $request->validate([
            'name' => [$supplier ? 'sometimes' : 'required', 'string', 'max:80'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'vat_number' => ['sometimes', 'nullable', 'digits:15'],
            'payment_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:300'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if (! $supplier) {
            abort_if(CashierSupplier::query()->where('store_id', $store->id)->count() >= 300, 422, 'وصلت للحد الأقصى للموردين.');
            $supplier = CashierSupplier::query()->create($data + ['store_id' => $store->id]);
        } else {
            $supplier->fill($data)->save();
        }

        return response()->json(['message' => 'تم حفظ المورد.', 'data' => $this->supply->supplierPayload($supplier)], $request->isMethod('post') ? 201 : 200);
    }

    public function deleteSupplier(Request $request, CashierSupplier $supplier): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($supplier->store_id === $store->id, 404);
        if (CashierPurchase::query()->where('supplier_id', $supplier->id)->where('status', '!=', 'cancelled')->exists()) {
            $supplier->forceFill(['active' => false])->save();

            return response()->json(['message' => 'المورد له فواتير، فأوقفناه بدل الحذف عشان يبقى سجله.']);
        }
        $supplier->delete();

        return response()->json(['message' => 'تم حذف المورد.']);
    }

    public function statement(Request $request, CashierSupplier $supplier): JsonResponse
    {
        $store = $this->store($request, 'reports');
        abort_unless($supplier->store_id === $store->id, 404);

        return response()->json(['data' => $this->supply->statement($store, $supplier)]);
    }

    public function pay(Request $request, CashierSupplier $supplier): JsonResponse
    {
        $store = $this->store($request, 'reports');
        abort_unless($supplier->store_id === $store->id, 404);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'method' => ['required', 'in:cash,transfer,card'],
            'note' => ['nullable', 'string', 'max:200'],
            'purchase_id' => ['nullable', 'integer'],
        ]);
        $only = isset($data['purchase_id']) ? CashierPurchase::query()->where('store_id', $store->id)->where('supplier_id', $supplier->id)->findOrFail($data['purchase_id']) : null;
        $result = $this->supply->pay($store, $supplier, (float) $data['amount'], $data['method'], $data['note'] ?? null, $request->user()?->id, $only);

        return response()->json(['message' => 'تم تسجيل السداد.', 'data' => $result], 201);
    }

    // ------------------------------------------------------------ purchases

    public function purchases(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $status = $request->validate(['status' => ['nullable', 'in:draft,received,cancelled,unpaid']])['status'] ?? null;
        $rows = CashierPurchase::query()->where('store_id', $store->id)
            ->when($status === 'unpaid', fn ($q) => $q->where('status', 'received')->whereColumn('paid', '<', 'total'))
            ->when($status && $status !== 'unpaid', fn ($q) => $q->where('status', $status))
            ->with(['items', 'supplier'])->latest('id')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn ($p) => $this->hideMoney($request, $this->supply->purchasePayload($p)))->values()]);
    }

    public function storePurchase(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $data = $this->purchaseRules($request, true);
        if (! empty($data['paid_now'])) {
            abort_unless($this->ctx($request)->can('reports'), 403, 'تسجيل السداد يحتاج صلاحية التقارير.');
        }
        $purchase = $this->supply->createPurchase($store, $data, $request->user()?->id);

        return response()->json([
            'message' => $purchase->status === 'received' ? 'تم استلام الفاتورة وإضافة الكميات للمخزون.' : 'تم حفظ طلب الشراء.',
            'data' => $this->supply->purchasePayload($purchase),
        ], 201);
    }

    public function updatePurchase(Request $request, CashierPurchase $purchase): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($purchase->store_id === $store->id, 404);
        $purchase = $this->supply->updateDraft($store, $purchase, $this->purchaseRules($request, false));

        return response()->json(['message' => 'تم تعديل طلب الشراء.', 'data' => $this->supply->purchasePayload($purchase)]);
    }

    public function receive(Request $request, CashierPurchase $purchase): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($purchase->store_id === $store->id, 404);
        $purchase = $this->supply->receive($store, $purchase);

        return response()->json(['message' => 'تم الاستلام وإضافة الكميات للمخزون.', 'data' => $this->supply->purchasePayload($purchase)]);
    }

    public function cancelPurchase(Request $request, CashierPurchase $purchase): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($purchase->store_id === $store->id, 404);

        return response()->json(['message' => 'تم إلغاء طلب الشراء.', 'data' => $this->supply->purchasePayload($this->supply->cancel($purchase)->load('items'))]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $store = $this->store($request);
        if ($request->isMethod('post')) {
            $created = $this->supply->applyReorder($store, $request->user()?->id);

            return response()->json([
                'message' => $created === [] ? 'ما فيه مواد تحتاج طلب جديد.' : 'جهّزنا '.count($created).' طلب شراء.',
                'data' => array_map(fn ($p) => $this->supply->purchasePayload($p), $created),
            ], $created === [] ? 200 : 201);
        }

        return response()->json(['data' => $this->supply->reorderSuggestions($store)]);
    }

    /** Which supplier a material is bought from, and how much to order when it runs low. */
    public function materialSupply(Request $request, CashierMaterial $material): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($material->store_id === $store->id, 404);
        $data = $request->validate([
            'supplier_id' => ['sometimes', 'nullable', 'integer'],
            'reorder_quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:10000000'],
            'low_alert' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);
        if (! empty($data['supplier_id'])) {
            CashierSupplier::query()->where('store_id', $store->id)->findOrFail($data['supplier_id']);
        }
        $material->fill($data)->save();
        $this->supply->afterMaterialsUsed($store);

        return response()->json(['message' => 'تم الحفظ.', 'data' => $material->fresh()]);
    }

    // ------------------------------------------------------------ branches

    public function saveBranch(Request $request, ?CashierBranch $branch = null): JsonResponse
    {
        $store = $this->store($request);
        abort_if($branch && $branch->store_id !== $store->id, 404);
        $data = $request->validate([
            'name' => [$branch ? 'sometimes' : 'required', 'string', 'max:60'],
            'kind' => ['sometimes', 'in:shop,stall,warehouse'],
            'address' => ['sometimes', 'nullable', 'string', 'max:160'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $this->supply->mainBranch($store);
        if (! $branch) {
            abort_if(CashierBranch::query()->where('store_id', $store->id)->count() >= 30, 422, 'وصلت للحد الأقصى للفروع.');
            $branch = CashierBranch::query()->create($data + ['store_id' => $store->id, 'kind' => $data['kind'] ?? 'shop']);
        } else {
            if ($branch->is_main) {
                unset($data['kind'], $data['active']);
            }
            $branch->fill($data)->save();
        }

        return response()->json(['message' => 'تم حفظ الفرع.', 'data' => $branch->only(['id', 'name', 'kind', 'address', 'is_main', 'active'])], $request->isMethod('post') ? 201 : 200);
    }

    public function deleteBranch(Request $request, CashierBranch $branch): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($branch->store_id === $store->id, 404);
        abort_if($branch->is_main, 422, 'المخزن الرئيسي ما ينحذف.');
        $held = \Illuminate\Support\Facades\DB::table('cashier_branch_stock')->where('branch_id', $branch->id)->where('quantity', '>', 0)->exists();
        abort_if($held, 422, 'الفرع فيه مخزون. حوّله للمخزن الرئيسي أول، أو أوقف الفرع.');
        \Illuminate\Support\Facades\DB::table('cashier_branch_stock')->where('branch_id', $branch->id)->delete();
        CashierDevice::query()->where('branch_id', $branch->id)->update(['branch_id' => null]);
        $branch->delete();

        return response()->json(['message' => 'تم حذف الفرع.']);
    }

    public function branchStock(Request $request, CashierBranch $branch): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($branch->store_id === $store->id, 404);

        return response()->json(['data' => [
            'branch' => $branch->only(['id', 'name', 'kind', 'address', 'is_main', 'active']),
            'items' => $this->supply->branchStock($store, $branch),
        ]]);
    }

    public function parLevels(Request $request, CashierBranch $branch): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($branch->store_id === $store->id, 404);
        $data = $request->validate([
            'levels' => ['required', 'array', 'max:500'],
            'levels.*.key' => ['required', 'string', 'regex:/^[pm]:\d+$/'],
            'levels.*.par_level' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);
        $this->supply->setParLevels($store, $branch, collect($data['levels'])->mapWithKeys(fn ($l) => [$l['key'] => $l['par_level'] ?? null])->all());
        $created = $this->supply->autoRefill($store, $branch, $request->user()?->id);

        return response()->json([
            'message' => $created === [] ? 'تم حفظ الحد الأدنى.' : 'تم الحفظ، وجهّزنا تحويل تعبئة للفرع.',
            'data' => $this->supply->branchStock($store, $branch),
        ]);
    }

    public function assignDevice(Request $request, CashierDevice $device): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($device->store_id === $store->id, 404);
        $branchId = $request->validate(['branch_id' => ['nullable', 'integer']])['branch_id'] ?? null;
        if ($branchId) {
            CashierBranch::query()->where('store_id', $store->id)->findOrFail($branchId);
        }
        $device->forceFill(['branch_id' => $branchId])->save();

        return response()->json(['message' => 'تم ربط الجهاز بالفرع.', 'data' => $device->only(['id', 'name', 'kind', 'branch_id'])]);
    }

    // ------------------------------------------------------------ transfers

    public function transfers(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $branches = $this->supply->branches($store);
        $rows = CashierTransfer::query()->where('store_id', $store->id)->latest('id')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn ($t) => $this->supply->transferPayload($t, $branches))->values()]);
    }

    public function storeTransfer(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate([
            'from_branch_id' => ['required', 'integer'],
            'to_branch_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.key' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'note' => ['nullable', 'string', 'max:300'],
            'complete' => ['sometimes', 'boolean'],
        ]);
        $from = CashierBranch::query()->where('store_id', $store->id)->findOrFail($data['from_branch_id']);
        $to = CashierBranch::query()->where('store_id', $store->id)->findOrFail($data['to_branch_id']);
        $transfer = $this->supply->createTransfer($store, $from, $to, $data['items'], $data['note'] ?? null, $request->user()?->id, (bool) ($data['complete'] ?? true));

        return response()->json([
            'message' => $transfer->status === 'done' ? 'تم التحويل.' : 'تم حفظ التحويل كمسودة.',
            'data' => $this->supply->transferPayload($transfer),
        ], 201);
    }

    public function completeTransfer(Request $request, CashierTransfer $transfer): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($transfer->store_id === $store->id, 404);

        return response()->json(['message' => 'تم التحويل.', 'data' => $this->supply->transferPayload($this->supply->completeTransfer($store, $transfer))]);
    }

    public function cancelTransfer(Request $request, CashierTransfer $transfer): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($transfer->store_id === $store->id, 404);

        return response()->json(['message' => 'تم إلغاء التحويل.', 'data' => $this->supply->transferPayload($this->supply->cancelTransfer($transfer))]);
    }

    public function refill(Request $request): JsonResponse
    {
        $store = $this->store($request);
        if ($request->isMethod('post')) {
            $created = $this->supply->autoRefill($store, null, $request->user()?->id);

            return response()->json([
                'message' => $created === [] ? 'كل الفروع فوق الحد الأدنى، أو فيه تحويلات تعبئة تنتظر اعتمادك.' : 'جهّزنا '.count($created).' تحويل تعبئة.',
                'data' => array_map(fn ($t) => $this->supply->transferPayload($t), $created),
            ], $created === [] ? 200 : 201);
        }

        return response()->json(['data' => $this->supply->refillSuggestions($store)]);
    }

    // ------------------------------------------------------------ helpers

    private function purchaseRules(Request $request, bool $create): array
    {
        return $request->validate([
            'supplier_id' => [$create ? 'required' : 'sometimes', 'integer'],
            'branch_id' => ['sometimes', 'nullable', 'integer'],
            'items' => [$create ? 'required' : 'sometimes', 'array', 'min:1', 'max:100'],
            'items.*.material_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0', 'max:10000000'],
            'items.*.unit_cost' => ['required_with:items', 'numeric', 'min:0', 'max:1000000'],
            'vat_mode' => ['sometimes', 'in:none,add,included'],
            'supplier_ref' => ['sometimes', 'nullable', 'string', 'max:40'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
            'receive' => ['sometimes', 'boolean'],
            'paid_now' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000000'],
            'method' => ['sometimes', 'in:cash,transfer,card'],
        ]);
    }

    private function hideMoney(Request $request, array $payload): array
    {
        return $this->ctx($request)->can('reports') ? $payload : array_diff_key($payload, array_flip(['paid', 'remaining', 'payment_status', 'overdue']));
    }

    private function ctx(Request $request): CashierContext
    {
        return $this->staff->resolve($request);
    }

    private function store(Request $request, ?string $extra = null): Store
    {
        abort_unless($this->supply->ready(), 503, 'الميزة قيد التجهيز، جرّب بعد دقائق.');
        $ctx = $this->ctx($request);
        abort_unless($ctx->can('inventory'), 403, 'ما عندك صلاحية المخزون.');
        if ($extra) {
            abort_unless($ctx->can($extra), 403, 'ما عندك صلاحية التقارير.');
        }
        abort_unless(app(CashierInventoryService::class)->active($ctx->store), 403, 'الموردين والمشتريات والفروع ضمن كاشير زاد برو.');

        return $ctx->store;
    }
}
