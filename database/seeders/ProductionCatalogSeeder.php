<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ProductionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path(
            'seeders/data/marketplace_catalog.json',
        );

        if (! is_file($path)) {
            throw new RuntimeException(
                'Marketplace catalog file was not found.',
            );
        }

        $catalog = json_decode(
            file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        DB::transaction(function () use ($catalog): void {
            $familyIds = $this->seedFamilies(
                $catalog['families'] ?? [],
            );

            $categoryIds = $this->seedCategories(
                $catalog['categories'] ?? [],
            );

            $storeIds = $this->seedStores(
                $catalog['stores'] ?? [],
                $familyIds,
            );

            $this->seedProducts(
                $catalog['products'] ?? [],
                $storeIds,
                $categoryIds,
            );
        });

        $this->command?->info(
            'Production marketplace catalog seeded successfully.',
        );
    }

    private function seedFamilies(array $items): array
    {
        $ids = [];

        foreach ($items as $index => $item) {
            $key = trim((string) ($item['source_key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $code = strtoupper(
                str_replace('-', '_', $key),
            );

            $values = [
                'owner_name' => 'حساب كتالوج تجريبي',
                'phone' => 'demo-'.$key,
                'email' => null,
                'health_certificate_number' => null,
                'health_certificate_expires_at' => null,
                'status' => 'active',
                'city_id' => null,
                'approved_by' => null,
                'approved_at' => now(),
                'metadata' => json_encode([
                    'source' => 'sanitized-production-catalog',
                ], JSON_UNESCAPED_UNICODE),
                'deleted_at' => null,
                'updated_at' => now(),
            ];

            $existing = DB::table('productive_families')
                ->where('code', $code)
                ->first();

            if ($existing !== null) {
                DB::table('productive_families')
                    ->where('id', $existing->id)
                    ->update($values);

                $id = (int) $existing->id;
            } else {
                $id = (int) DB::table('productive_families')
                    ->insertGetId([
                        'code' => $code,
                        ...$values,
                        'created_at' => now(),
                    ]);
            }

            $ids[$key] = $id;
        }

        return $ids;
    }

    private function seedCategories(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            $slug = trim((string) ($item['slug'] ?? ''));

            if ($slug === '') {
                continue;
            }

            $category = Category::query()
                ->withTrashed()
                ->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'parent_id' => null,
                        'name_ar' => $item['name_ar'],
                        'name_en' => $item['name_en'],
                        'image_path' =>
                            $item['image_path'] ?? null,
                        'sort_order' =>
                            (int) ($item['sort_order'] ?? 0),
                        'is_active' => true,
                    ],
                );

            if ($category->trashed()) {
                $category->restore();
            }

            $ids[$slug] = $category->id;
        }

        return $ids;
    }

    private function seedStores(
        array $items,
        array $familyIds,
    ): array {
        $ids = [];

        foreach ($items as $item) {
            $slug = trim((string) ($item['slug'] ?? ''));
            $familyKey = trim(
                (string) ($item['family_key'] ?? ''),
            );

            if (
                $slug === '' ||
                ! isset($familyIds[$familyKey])
            ) {
                $this->command?->warn(
                    "Skipped store: {$slug}",
                );

                continue;
            }

            $store = Store::query()
                ->withTrashed()
                ->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'productive_family_id' =>
                            $familyIds[$familyKey],
                        'city_id' => null,
                        'name_ar' => $item['name_ar'],
                        'name_en' => $item['name_en'] ?? null,
                        'description_ar' =>
                            $item['description_ar'] ?? null,
                        'description_en' =>
                            $item['description_en'] ?? null,
                        'logo_path' =>
                            $item['logo_path'] ?? null,
                        'cover_path' =>
                            $item['cover_path'] ?? null,
                        'status' => 'active',
                        'is_open' => true,
                        'rating' =>
                            (float) ($item['rating'] ?? 0),
                        'rating_count' =>
                            (int) ($item['rating_count'] ?? 0),
                        'working_hours' =>
                            $item['working_hours'] ?? null,
                    ],
                );

            if ($store->trashed()) {
                $store->restore();
            }

            $ids[$slug] = $store->id;
        }

        return $ids;
    }

    private function seedProducts(
        array $items,
        array $storeIds,
        array $categoryIds,
    ): void {
        foreach ($items as $item) {
            $sourceId = (int) ($item['source_id'] ?? 0);
            $storeSlug = trim(
                (string) ($item['store_slug'] ?? ''),
            );
            $categorySlug = trim(
                (string) ($item['category_slug'] ?? ''),
            );

            if (
                $sourceId < 1 ||
                ! isset($storeIds[$storeSlug])
            ) {
                $this->command?->warn(
                    "Skipped product source ID: {$sourceId}",
                );

                continue;
            }

            $sku = trim((string) ($item['sku'] ?? ''));

            if ($sku === '') {
                $sku = 'ZAD-CATALOG-'.$sourceId;
            }

            $values = [
                'store_id' => $storeIds[$storeSlug],
                'category_id' =>
                    $categoryIds[$categorySlug] ?? null,
                'name_ar' => $item['name_ar'],
                'name_en' => $item['name_en'] ?? null,
                'description_ar' =>
                    $item['description_ar'] ?? null,
                'description_en' =>
                    $item['description_en'] ?? null,
                'price' => (float) $item['price'],
                'compare_at_price' =>
                    $item['compare_at_price'] ?? null,
                'status' => 'active',
                'is_available' => true,
                'preparation_minutes' => max(
                    0,
                    (int) ($item['preparation_minutes'] ?? 0),
                ),
                'images' => is_array($item['images'] ?? null)
                    ? $item['images']
                    : [],
                'variants' => is_array(
                    $item['variants'] ?? null,
                ) ? $item['variants'] : null,
                'ingredients' => is_array(
                    $item['ingredients'] ?? null,
                ) ? $item['ingredients'] : null,
            ];

            if (
                Schema::hasColumn(
                    'products',
                    'preparation_mode',
                )
            ) {
                $values['preparation_mode'] =
                    $item['preparation_mode']
                        ?? Product::PREPARATION_READY_STOCK;
            }

            if (
                Schema::hasColumn(
                    'products',
                    'package_size',
                )
            ) {
                $values['package_size'] =
                    $item['package_size'] ?? null;
            }

            $product = Product::query()
                ->withTrashed()
                ->updateOrCreate(
                    ['sku' => $sku],
                    $values,
                );

            if ($product->trashed()) {
                $product->restore();
            }
        }
    }
}