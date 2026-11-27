<?php

namespace App\Http\Controllers\Api\Receipts;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\SaleReceipt;
use App\Models\Store;
use App\Services\SaleReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Every receipt of the family's own store. */
class FamilySaleReceiptController extends Controller
{
    public function __construct(private readonly SaleReceiptService $receipts) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->store($request);
        if (! $this->receipts->available()) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);
        $query = SaleReceipt::query()->with('order:id,number')->where('store_id', $store->id)->orderByDesc('issued_at');
        SaleReceiptFilters::apply($query, $validated);
        $page = $query->paginate((int) ($validated['per_page'] ?? 30));

        return response()->json([
            'data' => collect($page->items())->map(fn (SaleReceipt $r) => $this->receipts->payload($r))->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function pdf(Request $request, SaleReceipt $receipt): Response
    {
        abort_unless($receipt->store_id === $this->store($request)->id, 404);

        return response($this->receipts->pdf($receipt->loadMissing('order:id,number')), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->receipts->filename($receipt).'"',
        ]);
    }

    private function store(Request $request): Store
    {
        $profile = AppProfile::query()->where('user_id', $request->user()->id)->first();
        abort_unless($profile?->productive_family_id !== null, 403, 'هذا الحساب غير مرتبط بأسرة منتجة.');
        $store = Store::query()->where('productive_family_id', $profile->productive_family_id)->first();
        abort_unless($store !== null, 404, 'لم يتم العثور على متجر الأسرة.');

        return $store;
    }
}
