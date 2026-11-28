<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\CashierBatch;
use App\Models\Product;
use App\Services\App\CashierBatchService;
use App\Services\App\CashierContext;
use App\Services\App\CashierStaffService;
use App\Services\App\CashierZadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Packaging station, supervisor approvals and the cashier's «+20» feed. */
class CashierBatchController extends Controller
{
    public function __construct(
        private readonly CashierBatchService $batches,
        private readonly CashierStaffService $staff,
        private readonly CashierZadService $cashier,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx->can('packaging') || $ctx->can('approve') || $ctx->can('inventory'), 403, 'ما عندك صلاحية التغليف.');
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $day = isset($data['date']) ? \Illuminate\Support\Carbon::parse($data['date'], 'Asia/Riyadh') : now('Asia/Riyadh');
        $rows = CashierBatch::query()->where('store_id', $ctx->store->id)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when(($data['status'] ?? null) !== 'pending', fn ($q) => $q->whereBetween('created_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()]))
            ->with('product')->latest('id')->limit(200)->get();
        $products = Product::query()->where('store_id', $ctx->store->id)->whereNull('deleted_at')->where('status', '!=', 'archived')
            ->orderBy('name_ar')->get(['id', 'name_ar', 'images', 'track_stock', 'stock_quantity']);

        return response()->json(['data' => [
            'batches' => $rows->map(fn ($b) => $this->batches->payload($b))->values(),
            'pending' => CashierBatch::query()->where('store_id', $ctx->store->id)->where('status', 'pending')->count(),
            'today_quantity' => (int) $rows->where('status', 'approved')->sum('approved_quantity'),
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id, 'name' => $p->name_ar,
                'image' => is_array($p->images) ? ($p->images[0] ?? null) : null,
                'stock' => $p->track_stock ? (int) $p->stock_quantity : null,
                'can_make' => $this->batches->canMake($p),
            ])->values(),
            'locations' => CashierBatchService::LOCATIONS,
            'settings' => $this->batches->settings($ctx->store),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx->can('packaging'), 403, 'ما عندك صلاحية التغليف.');
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'location' => ['nullable', 'string', 'max:40'],
            'ready_in_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'note' => ['nullable', 'string', 'max:300'],
            'photo' => ['nullable', 'image', 'max:1024'],
        ]);
        $product = Product::query()->where('store_id', $ctx->store->id)->findOrFail($data['product_id']);
        $batch = $this->batches->create($ctx, $product, $data, $request->file('photo'));

        return response()->json([
            'message' => $batch->status === 'approved' ? 'تمت الإضافة للمخزون ✓' : 'وصلت للمشرف للاعتماد.',
            'data' => $this->batches->payload($batch->fresh('product')),
        ], 201);
    }

    public function approve(Request $request, CashierBatch $batch): JsonResponse
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx->can('approve'), 403, 'الاعتماد للمشرف أو المالك.');
        abort_unless($batch->store_id === $ctx->store->id, 404);
        $data = $request->validate(['quantity' => ['nullable', 'integer', 'min:0', 'max:100000']]);
        $batch = $this->batches->approve($ctx, $batch, $data['quantity'] ?? null);

        return response()->json(['message' => 'اعتمدت ✓', 'data' => $this->batches->payload($batch->fresh('product'))]);
    }

    public function reject(Request $request, CashierBatch $batch): JsonResponse
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx->can('approve'), 403, 'الاعتماد للمشرف أو المالك.');
        abort_unless($batch->store_id === $ctx->store->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return response()->json(['data' => $this->batches->payload($this->batches->reject($ctx, $batch, $data['reason'])->fresh('product'))]);
    }

    /** Cashier: batches that reached stock in the last 3 hours, for the «+20» notice. */
    public function live(Request $request): JsonResponse
    {
        $ctx = $this->ctx($request);
        $rows = CashierBatch::query()->where('store_id', $ctx->store->id)
            ->whereIn('status', ['approved', 'pending'])->where('created_at', '>=', now()->subHours(3))
            ->with('product')->latest('id')->limit(30)->get();

        return response()->json(['data' => $rows->map(fn ($b) => $this->batches->payload($b))->values()]);
    }

    public function photo(Request $request, CashierBatch $batch): StreamedResponse
    {
        $ctx = $this->ctx($request);
        abort_unless($batch->store_id === $ctx->store->id && $batch->photo_path && ! $batch->photo_deleted_at, 404);

        return Storage::disk($this->batches->disk())->response($batch->photo_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->batches->settings($this->ctx($request)->store)]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx->isOwner(), 403, 'هذه للمالك فقط.');
        $data = $request->validate([
            'photos_enabled' => ['sometimes', 'boolean'],
            'monthly_goal' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000'],
        ]);

        return response()->json(['data' => $this->batches->saveSettings($ctx->store, $data)]);
    }

    private function ctx(Request $request): CashierContext
    {
        abort_unless($this->batches->ready(), 503, 'الميزة قيد التجهيز.');
        $ctx = $this->staff->resolve($request);
        abort_unless((bool) ($this->cashier->entitlement($ctx->store->productiveFamily)['features']['batches'] ?? false), 403,
            'التغليف والمخزون من مزايا كاشير زاد برو.');

        return $ctx;
    }
}
