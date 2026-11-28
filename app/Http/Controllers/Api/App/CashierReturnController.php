<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\SaleReceipt;
use App\Models\SaleReturn;
use App\Services\App\CashierStaffService;
use App\Services\App\CashierZadService;
use App\Services\App\SaleReturnService;
use App\Services\SaleReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Returns / exchanges at the cashier (owner, or staff with the «refund» permission). */
class CashierReturnController extends Controller
{
    public function __construct(
        private readonly CashierStaffService $staff,
        private readonly SaleReturnService $returns,
        private readonly SaleReceiptService $receipts,
        private readonly CashierZadService $cashier,
    ) {}

    /** Today's and recent direct sales, newest first, or one sale by its receipt number. */
    public function recent(Request $request): JsonResponse
    {
        $ctx = $this->ctx($request);
        $q = trim((string) $request->query('number', ''));
        $rows = SaleReceipt::query()->where('store_id', $ctx->store->id)->where('sale_type', '!=', SaleReceipt::TYPE_PLATFORM)
            ->where('status', 'paid')
            ->when($q !== '', fn ($w) => $w->where('number', 'like', '%'.str_replace(['%', '_'], '', strtoupper($q)).'%'))
            ->when($q === '', fn ($w) => $w->where('issued_at', '>=', now()->subDays(30)))
            ->latest('id')->limit(40)->get();

        return response()->json(['data' => $rows->map(fn (SaleReceipt $r) => [
            'id' => $r->id, 'number' => $r->number, 'total' => (float) $r->total,
            'returned_total' => (float) ($r->returned_total ?? 0), 'issued_at' => $r->issued_at?->toISOString(),
            'items_count' => array_sum(array_map(fn ($i) => (int) $i['quantity'], $r->items ?? [])),
            'staff_name' => $r->staff_name, 'payment_method' => $r->payment_method,
        ])->values()]);
    }

    public function returnable(Request $request, SaleReceipt $receipt): JsonResponse
    {
        $ctx = $this->ctx($request);
        abort_unless($receipt->store_id === $ctx->store->id, 404);

        return response()->json(['data' => [
            'receipt' => $this->receipts->payload($receipt),
            'lines' => $this->returns->returnable($receipt),
            'returns' => SaleReturn::query()->where('sale_receipt_id', $receipt->id)->latest('id')->get()
                ->map(fn (SaleReturn $r) => $this->returns->payload($r))->values(),
            'methods' => collect(SaleReturnService::REFUND_LABELS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
        ]]);
    }

    public function store(Request $request, SaleReceipt $receipt): JsonResponse
    {
        $ctx = $this->ctx($request);
        abort_unless($receipt->store_id === $ctx->store->id, 404);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:60'],
            'lines.*.line' => ['required', 'integer', 'min:0'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:10000'],
            'reason' => ['required', 'string', 'min:2', 'max:200'],
            'refund_method' => ['required', 'in:'.implode(',', SaleReturnService::REFUND_METHODS)],
            'restock' => ['sometimes', 'boolean'],
        ], ['reason.required' => 'اكتب سبب المرتجع.']);
        $return = $this->returns->create($receipt, $data['lines'], $data['reason'], $data['refund_method'], (bool) ($data['restock'] ?? false),
            $ctx->staff ? ['cashier_staff_id' => $ctx->staff->id, 'staff_name' => $ctx->staff->name] : []);

        return response()->json([
            'message' => (float) $return->vat_amount > 0 ? 'تم المرتجع وإصدار إشعار دائن.' : 'تم تسجيل المرتجع.',
            'data' => $this->returns->payload($return),
        ], 201);
    }

    private function ctx(Request $request): \App\Services\App\CashierContext
    {
        $ctx = $this->staff->resolve($request);
        abort_unless($this->returns->ready(), 503, 'الخدمة غير جاهزة حالياً.');
        abort_unless((bool) ($this->cashier->entitlement($ctx->store->productiveFamily)['features']['quick_sale'] ?? false), 403,
            'هذه الميزة تتطلب كاشير زاد.');
        abort_unless($ctx->can('refund'), 403, 'ما عندك صلاحية المرتجع.');

        return $ctx;
    }
}
