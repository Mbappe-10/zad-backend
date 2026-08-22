<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Product;
use App\Models\Store;
use App\Services\Image\ProductImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FamilyProductController extends Controller
{
    private const PRODUCT_LIMIT = 10;

    public function __construct(
        private readonly ProductImageService $images,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $store = $this->familyStore($request);

        $products = Product::query()
            ->where('store_id', $store->id)
            ->latest()
            ->get()
            ->map(
                fn (Product $product): array => $this->payload($product),
            )
            ->values();

        return response()->json([
            'data' => $products,
            'meta' => [
                'count' => $products->count(),
                'limit' => self::PRODUCT_LIMIT,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->familyStore($request);
        $data = $this->validatedProduct($request);

        $product = DB::transaction(
            function () use ($store, $data): Product {
                DB::table('stores')
                    ->where('id', $store->id)
                    ->lockForUpdate()
                    ->first();

                $productsCount = Product::query()
                    ->where('store_id', $store->id)
                    ->count();

                if ($productsCount >= self::PRODUCT_LIMIT) {
                    throw ValidationException::withMessages([
                        'product' => [
                            'الحد الأقصى للمتجر هو 10 منتجات.',
                        ],
                    ]);
                }

                return Product::query()->create([
                    ...$data,
                    'store_id' => $store->id,
                    'status' => 'active',
                ]);
            },
        );

        return response()->json([
            'message' => 'تمت إضافة المنتج ونشره بنجاح.',
            'data' => $this->payload($product),
        ], 201);
    }

    public function update(
        Request $request,
        Product $product,
    ): JsonResponse {
        $this->ensureOwns($request, $product);

        $data = $this->validatedProduct($request);

        $product->update([
            ...$data,
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'تم تحديث المنتج بنجاح.',
            'data' => $this->payload($product->fresh()),
        ]);
    }

    public function availability(
        Request $request,
        Product $product,
    ): JsonResponse {
        $this->ensureOwns($request, $product);

        $data = $request->validate([
            'is_available' => [
                'required',
                'boolean',
            ],
        ]);

        $product->update([
            'is_available' => (bool) $data['is_available'],
        ]);

        return response()->json([
            'message' => $product->is_available
                ? 'المنتج متاح للعملاء الآن.'
                : 'تم إيقاف المنتج مؤقتًا.',
            'data' => $this->payload($product->fresh()),
        ]);
    }

    public function uploadImage(
        Request $request,
        Product $product,
    ): JsonResponse {
        $this->ensureOwns($request, $product);

        $data = $request->validate([
            'image' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:10240',
            ],
        ]);

        return response()->json([
            'message' => 'تم حفظ صورة المنتج.',
            'data' => $this->images->replace(
                $product,
                $data['image'],
            ),
        ]);
    }

    public function destroy(
        Request $request,
        Product $product,
    ): JsonResponse {
        $this->ensureOwns($request, $product);

        $this->images->deleteProductImage($product);
        $product->delete();

        return response()->json([
            'message' => 'تم حذف المنتج.',
        ]);
    }

    private function validatedProduct(
        Request $request,
    ): array {
        return $request->validate([
            'name_ar' => [
                'required',
                'string',
                'min:2',
                'max:180',
            ],
            'description_ar' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'price' => [
                'required',
                'numeric',
                'min:0.5',
                'max:999999',
            ],
            'compare_at_price' => [
                'nullable',
                'numeric',
                'min:0.5',
                'max:999999',
            ],
            'preparation_minutes' => [
                'required',
                'integer',
                'min:0',
                'max:1440',
            ],
            'package_size' => [
                'required',
                'in:small,medium,large,family',
            ],
            'is_available' => [
                'required',
                'boolean',
            ],
        ]);
    }

    private function familyStore(
        Request $request,
    ): Store {
        $profile = AppProfile::query()
            ->where('user_id', $request->user()->id)
            ->first();

        abort_unless(
            $profile?->productive_family_id !== null,
            403,
            'هذا الحساب غير مرتبط بأسرة منتجة.',
        );

        $store = Store::query()
            ->where(
                'productive_family_id',
                $profile->productive_family_id,
            )
            ->first();

        abort_unless(
            $store !== null,
            404,
            'لم يتم العثور على متجر الأسرة.',
        );

        return $store;
    }

    private function ensureOwns(
        Request $request,
        Product $product,
    ): void {
        $store = $this->familyStore($request);

        abort_unless(
            (int) $product->store_id === (int) $store->id,
            403,
        );
    }

    private function payload(
        Product $product,
    ): array {
        $images = is_array($product->images)
            ? $product->images
            : [];

        $firstImage = $images[0] ?? null;

        return [
            'id' => $product->id,
            'store_id' => $product->store_id,
            'name_ar' => $product->name_ar,
            'description_ar' => $product->description_ar,
            'price' => (float) $product->price,
            'compare_at_price' => $product->compare_at_price === null
                ? null
                : (float) $product->compare_at_price,
            'preparation_minutes' => (int) $product->preparation_minutes,
            'package_size' => $product->package_size ?? 'small',
            'status' => $product->status,
            'is_available' => (bool) $product->is_available,
            'images' => $images,
            'image_url' => is_string($firstImage)
                && $firstImage !== ''
                    ? Storage::disk('public')->url(
                        ltrim($firstImage, '/'),
                    )
                    : null,
            'created_at' => $product->created_at,
            'updated_at' => $product->updated_at,
        ];
    }
}