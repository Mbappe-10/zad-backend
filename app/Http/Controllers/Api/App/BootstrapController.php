<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Category;
use App\Models\City;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BootstrapController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $settings = AppSetting::query()
            ->where('is_public', true)
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->mapWithKeys(function (AppSetting $setting): array {
                return [
                    $setting->key => $this->castSettingValue(
                        $setting->value,
                        $setting->type,
                    ),
                ];
            });

        $categories = Category::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->limit(20)
            ->get([
                'id',
                'parent_id',
                'name_ar',
                'name_en',
                'slug',
                'image_path',
                'sort_order',
            ])
            ->map(function (Category $category): array {
                return [
                    'id' => $category->id,
                    'parent_id' => $category->parent_id,
                    'name_ar' => $category->name_ar,
                    'name_en' => $category->name_en,
                    'slug' => $category->slug,
                    'image_url' => $this->publicUrl($category->image_path),
                    'sort_order' => $category->sort_order,
                ];
            })
            ->values();

        $stores = DB::table('stores')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('is_open', true)
            ->orderByDesc('rating')
            ->orderByDesc('rating_count')
            ->limit(12)
            ->get([
                'id',
                'productive_family_id',
                'city_id',
                'name_ar',
                'name_en',
                'slug',
                'description_ar',
                'description_en',
                'logo_path',
                'cover_path',
                'rating',
                'rating_count',
                'working_hours',
            ])
            ->map(function (object $store): array {
                return [
                    'id' => $store->id,
                    'productive_family_id' => $store->productive_family_id,
                    'city_id' => $store->city_id,
                    'name_ar' => $store->name_ar,
                    'name_en' => $store->name_en,
                    'slug' => $store->slug,
                    'description_ar' => $store->description_ar,
                    'description_en' => $store->description_en,
                    'logo_url' => $this->publicUrl($store->logo_path),
                    'cover_url' => $this->publicUrl($store->cover_path),
                    'rating' => (float) $store->rating,
                    'rating_count' => (int) $store->rating_count,
                    'working_hours' => $this->decodeJson($store->working_hours),
                    'is_open' => true,
                ];
            })
            ->values();

        $products = Product::query()
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('is_available', true)
            ->orderByDesc('updated_at')
            ->limit(20)
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
                    'preparation_minutes' => (int) $product->preparation_minutes,
                    'package_size' => $product->package_size,
                    'images' => $images,
                    'primary_image_url' => $images[0] ?? null,
                    'variants' => $this->decodeJson($product->variants),
                    'ingredients' => $this->decodeJson($product->ingredients),
                    'is_available' => true,
                ];
            })
            ->values();

        $cities = City::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name_ar')
            ->get([
                'id',
                'code',
                'name_ar',
                'name_en',
                'delivery_base_fee',
            ])
            ->map(function (City $city): array {
                return [
                    'id' => $city->id,
                    'code' => $city->code,
                    'name_ar' => $city->name_ar,
                    'name_en' => $city->name_en,
                    'delivery_base_fee' => (float) $city->delivery_base_fee,
                ];
            })
            ->values();

        return response()->json([
            'app' => [
                'name' => 'زاد',
                'version' => '1.0.0',
                'guest_first' => true,
            ],
            'settings' => $settings,
            'categories' => $categories,
            'featured_stores' => $stores,
            'featured_products' => $products,
            'banners' => [],
            'cities' => $cities,
        ]);
    }

    private function castSettingValue(mixed $value, ?string $type): mixed
    {
        return match ($type) {
            'boolean', 'bool' => filter_var(
                $value,
                FILTER_VALIDATE_BOOLEAN,
            ),
            'integer', 'int' => (int) $value,
            'float', 'decimal', 'number' => (float) $value,
            'array', 'json', 'object' => $this->decodeJson($value),
            default => $value,
        };
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
            ->filter(fn (mixed $image): bool => is_string($image) && $image !== '')
            ->map(fn (string $image): string => $this->publicUrl($image) ?? $image)
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