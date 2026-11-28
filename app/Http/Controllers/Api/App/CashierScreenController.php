<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\CashierDevice;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Services\App\CashierScreenService;
use App\Services\App\CashierStaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menu screens. Public routes are for the TV/iPad itself (pairing code, menu feed,
 * receipt QR); owner routes let the family pair screens and design her menu.
 */
class CashierScreenController extends Controller
{
    public function __construct(
        private readonly CashierScreenService $screens,
        private readonly CashierStaffService $staff,
    ) {}

    // ------------------------------------------------------------ the screen

    public function pair(): JsonResponse
    {
        return response()->json(['data' => $this->screens->startPairing()]);
    }

    public function pollPair(string $poll): JsonResponse
    {
        return response()->json(['data' => ['token' => $this->screens->poll($poll)]]);
    }

    public function feed(Request $request): JsonResponse
    {
        $device = $this->screens->device($request->header('X-Zad-Screen'));
        $store = Store::query()->with('productiveFamily')->findOrFail($device->store_id);

        return response()->json(['data' => $this->screens->feed($store, $device)]);
    }

    public function receipt(Request $request): JsonResponse
    {
        $device = $this->screens->device($request->header('X-Zad-Screen'));

        return response()->json(['data' => $this->screens->receipt($device)]);
    }

    // ------------------------------------------------------------ the owner

    public function index(Request $request): JsonResponse
    {
        $store = $this->ownerStore($request);

        return response()->json(['data' => [
            'enabled' => $this->screens->enabled($store),
            'limit' => app(\App\Services\App\CashierZadService::class)->settings()['screens_limit'],
            'screens' => CashierDevice::query()->where('store_id', $store->id)->whereIn('kind', CashierScreenService::KINDS)
                ->whereNull('revoked_at')->latest('id')->get()->map(fn ($d) => $this->screens->devicePayload($d))->values(),
            'cashier_devices' => CashierDevice::query()->where('store_id', $store->id)->where('kind', 'cashier')
                ->whereNull('personal_staff_id')->whereNull('revoked_at')->get(['id', 'name']),
        ]]);
    }

    public function claim(Request $request): JsonResponse
    {
        $store = $this->ownerStore($request);
        $this->requireEnabled($store);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'kind' => ['required', Rule::in(CashierScreenService::KINDS)],
            'name' => ['required', 'string', 'max:60'],
        ]);
        $device = $this->screens->claim($store, $data['code'], $data['kind'], $data['name'], $request->user()->id);

        return response()->json(['message' => 'تم ربط الشاشة. بتشتغل خلال ثواني.', 'data' => $this->screens->devicePayload($device)], 201);
    }

    public function update(Request $request, CashierDevice $device): JsonResponse
    {
        $store = $this->ownerStore($request);
        abort_unless($device->store_id === $store->id && in_array($device->kind, CashierScreenService::KINDS, true), 404);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'kind' => ['sometimes', Rule::in(CashierScreenService::KINDS)],
            'show_receipts' => ['sometimes', 'boolean'],
            'receipt_devices' => ['sometimes', 'array'],
            'receipt_devices.*' => ['integer'],
            'layout' => ['sometimes', 'nullable', Rule::in(CashierScreenService::LAYOUTS)],
            'showcase_style' => ['sometimes', 'nullable', Rule::in(CashierScreenService::SHOWCASE_STYLES)],
        ]);
        $settings = array_replace(['show_receipts' => true, 'receipt_devices' => []], (array) ($device->settings ?? []));
        foreach (['show_receipts', 'receipt_devices', 'layout', 'showcase_style'] as $k) {
            if (array_key_exists($k, $data)) {
                $settings[$k] = $data[$k];
            }
        }
        $device->fill(array_intersect_key($data, array_flip(['name', 'kind'])) + ['settings' => $settings])->save();

        return response()->json(['message' => 'تم الحفظ.', 'data' => $this->screens->devicePayload($device->fresh())]);
    }

    public function destroy(Request $request, CashierDevice $device): JsonResponse
    {
        $store = $this->ownerStore($request);
        abort_unless($device->store_id === $store->id, 404);
        $device->forceFill(['revoked_at' => now()])->save();

        return response()->json(['message' => 'تم فصل الشاشة.']);
    }

    public function menu(Request $request): JsonResponse
    {
        $store = $this->ownerStore($request);
        $products = Product::query()->where('store_id', $store->id)->whereNull('deleted_at')->where('status', 'active')->orderBy('id')->get();

        return response()->json(['data' => [
            'enabled' => $this->screens->enabled($store),
            'menu' => $this->screens->menu($store),
            'products' => $products->map(fn ($p) => $this->screens->productPayload($p))->values(),
            'categories' => Category::query()->whereIn('id', $products->pluck('category_id')->filter()->unique())->get(['id', 'name_ar']),
            'preview' => $this->screens->feed($store),
            'themes' => CashierScreenService::THEMES,
            'layouts' => CashierScreenService::LAYOUTS,
            'showcase_styles' => CashierScreenService::SHOWCASE_STYLES,
        ]]);
    }

    public function saveMenu(Request $request): JsonResponse
    {
        $store = $this->ownerStore($request);
        $this->requireEnabled($store);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:60'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:120'],
            'theme' => ['sometimes', Rule::in(CashierScreenService::THEMES)],
            'accent' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'layout' => ['sometimes', Rule::in(CashierScreenService::LAYOUTS)],
            'font_scale' => ['sometimes', 'numeric', 'between:0.8,1.4'],
            'rotate_seconds' => ['sometimes', 'integer', 'between:5,120'],
            'show_prices' => ['sometimes', 'boolean'],
            'show_sold_out' => ['sometimes', 'boolean'],
            'hide_sold_out' => ['sometimes', 'boolean'],
            'show_offer' => ['sometimes', 'boolean'],
            'offer_title' => ['sometimes', 'nullable', 'string', 'max:40'],
            'offer_text' => ['sometimes', 'nullable', 'string', 'max:90'],
            'offer_price' => ['sometimes', 'nullable', 'string', 'max:30'],
            'offer_product_id' => ['sometimes', 'nullable', 'integer'],
            'hero_product_id' => ['sometimes', 'nullable', 'integer'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer'],
            'hidden_product_ids' => ['sometimes', 'array'],
            'hidden_product_ids.*' => ['integer'],
            'ticker' => ['sometimes', 'nullable', 'string', 'max:200'],
            'showcase_style' => ['sometimes', Rule::in(CashierScreenService::SHOWCASE_STYLES)],
            'image_fit' => ['sometimes', Rule::in(['full', 'fill'])],
            'showcase_seconds' => ['sometimes', 'integer', 'between:3,15'],
            'showcase_product_ids' => ['sometimes', 'array', 'max:30'],
            'showcase_product_ids.*' => ['integer'],
            'showcase_badges' => ['sometimes', 'array', 'max:30'],
            'showcase_badges.*' => ['nullable', 'string', 'max:24'],
        ]);
        foreach (['tagline', 'offer_title', 'offer_text', 'offer_price', 'ticker'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                $data[$k] = '';
            }
        }
        if (isset($data['font_scale'])) {
            $data['font_scale'] = round((float) $data['font_scale'], 2);
        }
        $this->screens->saveMenu($store, $data);

        return response()->json(['message' => 'تم حفظ المنيو. الشاشات بتتحدّث خلال ثواني.', 'data' => $this->screens->feed($store)]);
    }

    private function requireEnabled(Store $store): void
    {
        abort_unless($this->screens->enabled($store), 403, 'المنيو والشاشات من مزايا كاشير زاد برو.');
    }

    private function ownerStore(Request $request): Store
    {
        $ctx = $this->staff->resolve($request);
        abort_unless($ctx->isOwner() && $ctx->device === null, 403, 'هذه للمالكة فقط.');

        return $ctx->store;
    }
}
