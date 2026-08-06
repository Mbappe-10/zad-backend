<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StoreCatalogController extends Controller
{
    public function show(int $store): JsonResponse
    {
        $storeRecord = DB::table('stores')
            ->where('id', $store)
            ->whereNull('deleted_at')
            ->first();

        if ($storeRecord === null) {
            return response()->json([
                'message' => 'المتجر غير موجود.',
            ], 404);
        }

        if ($storeRecord->status !== 'active') {
            return response()->json([
                'message' => 'المتجر غير متاح حاليًا.',
            ], 403);
        }

        $products = Product::query()
            ->where('store_id', $storeRecord->id)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('is_available', true)
            ->orderByDesc('updated_at')
            ->get([
                'id',
                'store_id',
                'category_id',
                'sku',
                'name_ar',
                'name_en',
                'description_ar',
                'description_en',
                'price',
                'compare_at_price',
                'preparation_minutes',
                'images',
                'variants',
                'ingredients',
                'package_size',
                'is_available',
            ])
            ->map(function (Product $product): array {
                $images = $this->normalizeImages($product->images);

                return [
                    'id' => $product->id,
                    'store_id' => $product->store_id,
                    'category_id' => $product->category_id,
                    'sku' => $product->sku,
                    'name_ar' => $product->name_ar,
                    'name_en' => $product->name_en,
                    'description_ar' => $product->description_ar,
                    'description_en' => $product->description_en,
                    'price' => (float) $product->price,
                    'compare_at_price' => $product->compare_at_price !== null
                        ? (float) $product->compare_at_price
                        : null,
                    'preparation_minutes' =>
                        (int) $product->preparation_minutes,
                    'package_size' => $product->package_size,
                    'images' => $images,
                    'primary_image_url' => $images[0] ?? null,
                    'variants' => $this->decodeJson($product->variants),
                    'ingredients' => $this->decodeJson($product->ingredients),
                    'is_available' => (bool) $product->is_available,
                ];
            })
            ->values();

        return response()->json([
            'store' => [
                'id' => $storeRecord->id,
                'productive_family_id' =>
                    $storeRecord->productive_family_id,
                'city_id' => $storeRecord->city_id,
                'name_ar' => $storeRecord->name_ar,
                'name_en' => $storeRecord->name_en,
                'slug' => $storeRecord->slug,
                'description_ar' => $storeRecord->description_ar,
                'description_en' => $storeRecord->description_en,
                'logo_url' => $this->publicUrl($storeRecord->logo_path),
                'cover_url' => $this->publicUrl($storeRecord->cover_path),
                'rating' => (float) $storeRecord->rating,
                'rating_count' => (int) $storeRecord->rating_count,
                'working_hours' =>
                    $this->decodeJson($storeRecord->working_hours),
                'is_open' => (bool) $storeRecord->is_open,
            ],
            'products' => $products,
            'products_count' => $products->count(),
        ]);
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value) || is_object($value) || $value === null) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE
            ? $decoded
            : $value;
    }

    private function normalizeImages(mixed $value): array
    {
        $images = $this->decodeJson($value);

        if (! is_array($images)) {
            return [];
        }

        return collect($images)
            ->filter(
                fn (mixed $image): bool =>
                    is_string($image) && trim($image) !== '',
            )
            ->map(
                fn (string $image): string =>
                    $this->publicUrl($image) ?? $image,
            )
            ->values()
            ->all();
    }

    private function publicUrl(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        if (
            str_starts_with($path, 'http://') ||
            str_starts_with($path, 'https://')
        ) {
            return $path;
        }

        return Storage::disk('public')->url(
            ltrim($path, '/'),
        );
    }
}