<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PublicExperienceSeeder extends Seeder
{
    public function run(): void
    {
        $catalogPath = database_path(
            'seeders/data/marketplace_catalog.json'
        );

        if (!is_file($catalogPath)) {
            throw new RuntimeException(
                'Marketplace catalog file was not found.'
            );
        }

        $catalog = json_decode(
            file_get_contents($catalogPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->seedCities();
        $this->assignCatalogStoresToMakkah($catalog);
        $this->applyInternalTestingPickupLocation();
        $this->seedAdvertisements($catalog);
    }

    private function seedCities(): void
    {
        $cities = [
            [
                'code' => 'makkah',
                'name_ar' => 'مكة المكرمة',
                'name_en' => 'Makkah',
                'delivery_base_fee' => 10,
            ],
            [
                'code' => 'jeddah',
                'name_ar' => 'جدة',
                'name_en' => 'Jeddah',
                'delivery_base_fee' => 10,
            ],
            [
                'code' => 'riyadh',
                'name_ar' => 'الرياض',
                'name_en' => 'Riyadh',
                'delivery_base_fee' => 10,
            ],
        ];

        foreach ($cities as $city) {
            $exists = DB::table('cities')
                ->where('code', $city['code'])
                ->exists();

            $values = [
                'name_ar' => $city['name_ar'],
                'name_en' => $city['name_en'],
                'is_active' => true,
                'delivery_base_fee' =>
                    $city['delivery_base_fee'],
                'deleted_at' => null,
                'updated_at' => now(),
            ];

            if (!$exists) {
                $values['created_at'] = now();
            }

            DB::table('cities')->updateOrInsert(
                ['code' => $city['code']],
                $values,
            );
        }
    }

    private function assignCatalogStoresToMakkah(
        array $catalog,
    ): void {
        $makkahId = DB::table('cities')
            ->where('code', 'makkah')
            ->value('id');

        if (!$makkahId) {
            throw new RuntimeException(
                'Makkah city was not created.'
            );
        }

        $storeSlugs = collect($catalog['stores'] ?? [])
            ->pluck('slug')
            ->filter(
                static fn (mixed $slug): bool =>
                    is_string($slug) &&
                    trim($slug) !== '',
            )
            ->values()
            ->all();

        if ($storeSlugs !== []) {
            Store::query()
                ->whereIn('slug', $storeSlugs)
                ->update([
                    'city_id' => $makkahId,
                ]);
        }
    }

    private function seedAdvertisements(
        array $catalog,
    ): void {
        $ads = [
            [
                'external_key' => 'AD-23670155',
                'source_product_id' => 18,
                'title_ar' => 'كبسة دجاج (نصف حبة)',
                'title_en' =>
                    'Chicken Kabsa (Half Chicken)',
                'media_url' =>
                    'https://zad-backend-w2a2.onrender.com/catalog/banners/z6hrbUKxEvSmDBTFwrh0Ws7y6fhOdUp8ixebqn8c.png',
                'display_order' => 1,
            ],
            [
                'external_key' => 'AD-81981600',
                'source_product_id' => 16,
                'title_ar' =>
                    'فخامة الفستق... برائحة الورد',
                'title_en' =>
                    'Pistachio Elegance with Rose',
                'media_url' =>
                    'https://zad-backend-w2a2.onrender.com/catalog/banners/8oHnrDlkIUECQ4dh93M4P8vt0bo6qRoWBBjRMzOi.png',
                'display_order' => 2,
            ],
            [
                'external_key' => 'AD-81745716',
                'source_product_id' => 17,
                'title_ar' =>
                    'أربع نكهات... وسعادة لا تنتهي',
                'title_en' =>
                    'Four Flavors, Endless Happiness',
                'media_url' =>
                    'https://zad-backend-w2a2.onrender.com/catalog/banners/prQjCy38LsXQAgPU9NZhqHslQZUKoZBDGfYmQ5sI.png',
                'display_order' => 3,
            ],
        ];

        foreach ($ads as $ad) {
            $product = $this->productForSourceId(
                $ad['source_product_id'],
                $catalog,
            );

            $payload = [
                'titleAr' => $ad['title_ar'],
                'titleEn' => $ad['title_en'],
                'descriptionAr' => null,
                'descriptionEn' => null,
                'mediaType' => 'image',
                'mediaUrl' => $ad['media_url'],
                'imageUrl' => $ad['media_url'],
                'storeId' => (int) $product->store_id,
                'productId' => (int) $product->id,
                'displayOrder' =>
                    (int) $ad['display_order'],
                'status' => 'active',
                'visible' => true,
                'targetType' => 'product',
                'buttonTextAr' => 'اطلب العرض',
                'buttonTextEn' => 'Order Offer',
                'priority' => 'gold',
            ];

            $exists = DB::table('platform_records')
                ->where('resource', 'ads')
                ->where(
                    'external_key',
                    $ad['external_key'],
                )
                ->exists();

            $values = [
                'status' => 'active',
                'payload' => json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES |
                    JSON_THROW_ON_ERROR,
                ),
                'deleted_at' => null,
                'updated_at' => now(),
            ];

            if (!$exists) {
                $values['created_at'] = now();
            }

            DB::table('platform_records')
                ->updateOrInsert(
                    [
                        'resource' => 'ads',
                        'external_key' =>
                            $ad['external_key'],
                    ],
                    $values,
                );
        }
    }

    private function applyInternalTestingPickupLocation(): void
    {
        if (!(bool) config('internal_testing.enabled', false)) {
            return;
        }

        $storeSlug = trim((string) config(
            'internal_testing.family.store_slug',
            '',
        ));
        $latitude = config(
            'internal_testing.family.pickup_latitude',
        );
        $longitude = config(
            'internal_testing.family.pickup_longitude',
        );

        if (
            $storeSlug === '' ||
            !is_numeric($latitude) ||
            !is_numeric($longitude) ||
            (float) $latitude < -90 ||
            (float) $latitude > 90 ||
            (float) $longitude < -180 ||
            (float) $longitude > 180
        ) {
            return;
        }

        Store::query()
            ->where('slug', $storeSlug)
            ->update([
                'pickup_address' => trim((string) config(
                    'internal_testing.family.pickup_address',
                    'نقطة استلام تجريبية - مكة المكرمة',
                )),
                'pickup_latitude' => (float) $latitude,
                'pickup_longitude' => (float) $longitude,
            ]);
    }

    private function productForSourceId(
        int $sourceId,
        array $catalog,
    ): Product {
        $item = collect($catalog['products'] ?? [])
            ->first(
                static fn (array $row): bool =>
                    (int) (
                        $row['source_id'] ?? 0
                    ) === $sourceId,
            );

        if (!$item) {
            throw new RuntimeException(
                "Catalog product source ID {$sourceId} was not found."
            );
        }

        $storeSlug = trim(
            (string) ($item['store_slug'] ?? '')
        );

        $store = Store::query()
            ->where('slug', $storeSlug)
            ->first();

        if (!$store) {
            throw new RuntimeException(
                "Catalog store {$storeSlug} was not found."
            );
        }

        $query = Product::query()
            ->where('store_id', $store->id);

        $sku = trim(
            (string) ($item['sku'] ?? '')
        );

        if ($sku !== '') {
            $query->where('sku', $sku);
        } else {
            $query->where(
                'name_ar',
                (string) ($item['name_ar'] ?? ''),
            );
        }

        $product = $query->first();

        if (!$product) {
            throw new RuntimeException(
                "Production product for source ID {$sourceId} was not found."
            );
        }

        return $product;
    }
}
