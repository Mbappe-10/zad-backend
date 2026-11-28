<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\App\CashierStaffService;
use App\Services\App\ReceiptStyleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Cashier Zad Pro → «شكل الفاتورة»: the owner picks the classic or the long receipt. */
class ReceiptStyleController extends Controller
{
    public function __construct(private readonly CashierStaffService $staff, private readonly ReceiptStyleService $style) {}

    public function show(Request $request): JsonResponse
    {
        $ctx = $this->staff->resolve($request);
        abort_unless($ctx->isOwner(), 403, 'شكل الفاتورة لصاحب المتجر فقط.');

        return response()->json(['data' => $this->style->payload($ctx->store) + ['entitled' => $this->style->entitled($ctx->store)]]);
    }

    public function save(Request $request): JsonResponse
    {
        $ctx = $this->staff->resolve($request);
        abort_unless($ctx->isOwner(), 403, 'شكل الفاتورة لصاحب المتجر فقط.');
        abort_unless($this->style->ready(), 503, 'الخدمة غير جاهزة حالياً.');
        abort_unless($this->style->entitled($ctx->store), 403, 'شكل الفاتورة من مزايا كاشير زاد برو.');
        $data = $request->validate($this->style->rules());

        return response()->json(['message' => 'تم حفظ شكل الفاتورة.', 'data' => $this->style->save($ctx->store, $data) + ['entitled' => true]]);
    }
}
