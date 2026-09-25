<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LaunchCapacitySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') || env('ZAD_ALLOW_CAPACITY_SEED') !== '1') {
            throw new \RuntimeException(
                'Capacity fixtures are disabled. Use a local/testing/staging database and set ZAD_ALLOW_CAPACITY_SEED=1.',
            );
        }

        DB::disableQueryLog();
        $now = now();
        $cityId = DB::table('cities')->where('code', 'capacity-city')->value('id');

        if ($cityId === null) {
            $cityId = DB::table('cities')->insertGetId([
                'code' => 'capacity-city',
                'name_ar' => 'مدينة اختبار السعة',
                'name_en' => 'Capacity Test City',
                'is_active' => true,
                'delivery_base_fee' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $categoryId = DB::table('categories')->where('slug', 'capacity-products')->value('id');

        if ($categoryId === null) {
            $categoryId = DB::table('categories')->insertGetId([
                'name_ar' => 'منتجات اختبار السعة',
                'name_en' => 'Capacity Products',
                'slug' => 'capacity-products',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (array_chunk(range(1, 1000), 100) as $numbers) {
            $families = [];
            $customers = [];

            foreach ($numbers as $number) {
                $families[] = [
                    'code' => sprintf('CAP-FAM-%04d', $number),
                    'owner_name' => 'Capacity Family '.$number,
                    'phone' => sprintf('0598%06d', $number),
                    'status' => 'active',
                    'city_id' => $cityId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $customers[] = [
                    'name' => 'Capacity Customer '.$number,
                    'phone' => sprintf('0599%06d', $number),
                    'email' => sprintf('capacity.customer.%04d@example.test', $number),
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('productive_families')->upsert(
                $families,
                ['code'],
                ['owner_name', 'phone', 'status', 'city_id', 'updated_at'],
            );
            DB::table('customers')->upsert(
                $customers,
                ['phone'],
                ['name', 'email', 'status', 'updated_at'],
            );
        }

        $familyIds = DB::table('productive_families')
            ->where('code', 'like', 'CAP-FAM-%')
            ->pluck('id', 'code');

        foreach (array_chunk(range(1, 1000), 100) as $numbers) {
            $stores = [];

            foreach ($numbers as $number) {
                $stores[] = [
                    'productive_family_id' => $familyIds[sprintf('CAP-FAM-%04d', $number)],
                    'city_id' => $cityId,
                    'name_ar' => 'متجر سعة '.$number,
                    'name_en' => 'Capacity Store '.$number,
                    'slug' => sprintf('capacity-store-%04d', $number),
                    'status' => 'active',
                    'is_open' => true,
                    'pickup_latitude' => 21.3891,
                    'pickup_longitude' => 39.8579,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('stores')->upsert(
                $stores,
                ['slug'],
                ['productive_family_id', 'city_id', 'name_ar', 'name_en', 'status', 'is_open', 'updated_at'],
            );
        }

        $storeIds = DB::table('stores')
            ->where('slug', 'like', 'capacity-store-%')
            ->pluck('id', 'slug');

        foreach (array_chunk(range(1, 1000), 50) as $numbers) {
            $products = [];

            foreach ($numbers as $number) {
                $storeId = $storeIds[sprintf('capacity-store-%04d', $number)];

                for ($product = 1; $product <= 10; $product++) {
                    $products[] = [
                        'store_id' => $storeId,
                        'category_id' => $categoryId,
                        'sku' => sprintf('CAP-%04d-%02d', $number, $product),
                        'name_ar' => sprintf('منتج سعة %04d-%02d', $number, $product),
                        'name_en' => sprintf('Capacity Product %04d-%02d', $number, $product),
                        'price' => 25 + $product,
                        'status' => 'active',
                        'is_available' => true,
                        'preparation_minutes' => 5,
                        'package_size' => 'small',
                        'preparation_mode' => 'ready_stock',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            DB::table('products')->upsert(
                $products,
                ['sku'],
                ['store_id', 'category_id', 'name_ar', 'name_en', 'price', 'status', 'is_available', 'updated_at'],
            );
        }

        $productIds = DB::table('products')
            ->where('sku', 'like', 'CAP-%-01')
            ->pluck('id', 'sku');
        $fixtures = [];

        for ($number = 1; $number <= 1000; $number++) {
            $guestId = sprintf('00000000-0000-4000-8000-%012d', $number);
            $phone = sprintf('0597%06d', $number);
            DB::table('app_guest_sessions')->updateOrInsert(
                ['id' => $guestId],
                [
                    'device_id' => 'capacity-device-'.$number,
                    'latitude' => 21.3900,
                    'longitude' => 39.8600,
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            $verificationId = DB::table('phone_verifications')
                ->where('phone', $phone)
                ->where('guest_session_id', $guestId)
                ->value('id');

            if ($verificationId === null) {
                $verificationId = DB::table('phone_verifications')->insertGetId([
                    'phone' => $phone,
                    'purpose' => 'checkout',
                    'code_hash' => password_hash('000000', PASSWORD_BCRYPT),
                    'attempts' => 0,
                    'expires_at' => now()->addDay(),
                    'verified_at' => $now,
                    'guest_session_id' => $guestId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $fixtures[] = [
                'client' => $number,
                'payload' => [
                    'guest_session_id' => $guestId,
                    'verification_token' => encrypt([
                        'id' => $verificationId,
                        'phone' => $phone,
                        'purpose' => 'checkout',
                        'guest_session_id' => $guestId,
                        'expires' => now()->addDay()->timestamp,
                    ]),
                    'store_id' => $storeIds[sprintf('capacity-store-%04d', $number)],
                    'city_id' => $cityId,
                    'distance_km' => 2.5,
                    'latitude' => 21.3900,
                    'longitude' => 39.8600,
                    'address' => ['label' => 'Capacity test address'],
                    'items' => [[
                        'product_id' => $productIds[sprintf('CAP-%04d-01', $number)],
                        'quantity' => 1,
                    ]],
                ],
            ];
        }

        Storage::disk('local')->put(
            'private/capacity/fixtures.json',
            json_encode([
                'generated_at' => now()->toIso8601String(),
                'families' => 1000,
                'products' => 10000,
                'customers' => 1000,
                'clients' => $fixtures,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $this->command?->info('Created 1,000 families, 10,000 products, 1,000 customers and 1,000 order fixtures.');
        $this->command?->info(storage_path('app/private/capacity/fixtures.json'));
    }
}
