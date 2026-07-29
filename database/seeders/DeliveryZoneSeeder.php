<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\DeliveryZone;
use Illuminate\Database\Seeder;

class DeliveryZoneSeeder extends Seeder
{
    public function run(): void
    {
        $cities = City::query()
            ->whereIn('name_ar', [
                'مكة المكرمة',
                'جدة',
                'الرياض',
            ])
            ->get()
            ->keyBy('name_ar');

        $zones = [
            [
                'city' => 'مكة المكرمة',
                'name_ar' => 'مكة - المنطقة المركزية',
                'name_en' => 'Makkah Central Zone',
                'code' => 'MAK-CENTRAL',
                'base_delivery_fee' => 5,
                'per_km_fee' => 1,
                'minimum_delivery_fee' => 5,
                'maximum_distance_km' => 10,
                'estimated_delivery_minutes' => 30,
                'priority' => 10,
            ],
            [
                'city' => 'مكة المكرمة',
                'name_ar' => 'مكة - نطاق خارجي',
                'name_en' => 'Makkah Outer Zone',
                'code' => 'MAK-OUTER',
                'base_delivery_fee' => 8,
                'per_km_fee' => 1.5,
                'minimum_delivery_fee' => 8,
                'maximum_distance_km' => 20,
                'estimated_delivery_minutes' => 45,
                'priority' => 20,
            ],
            [
                'city' => 'جدة',
                'name_ar' => 'جدة - النطاق المركزي',
                'name_en' => 'Jeddah Central Zone',
                'code' => 'JED-CENTRAL',
                'base_delivery_fee' => 7,
                'per_km_fee' => 1.25,
                'minimum_delivery_fee' => 7,
                'maximum_distance_km' => 15,
                'estimated_delivery_minutes' => 35,
                'priority' => 10,
            ],
            [
                'city' => 'الرياض',
                'name_ar' => 'الرياض - النطاق المركزي',
                'name_en' => 'Riyadh Central Zone',
                'code' => 'RUH-CENTRAL',
                'base_delivery_fee' => 8,
                'per_km_fee' => 1.5,
                'minimum_delivery_fee' => 8,
                'maximum_distance_km' => 18,
                'estimated_delivery_minutes' => 40,
                'priority' => 10,
            ],
        ];

        foreach ($zones as $zone) {
            $city = $cities->get($zone['city']);

            if (! $city) {
                continue;
            }

            DeliveryZone::query()->updateOrCreate(
                [
                    'code' => $zone['code'],
                ],
                [
                    'city_id' => $city->id,
                    'name_ar' => $zone['name_ar'],
                    'name_en' => $zone['name_en'],
                    'base_delivery_fee' => $zone['base_delivery_fee'],
                    'per_km_fee' => $zone['per_km_fee'],
                    'minimum_delivery_fee' => $zone['minimum_delivery_fee'],
                    'maximum_distance_km' => $zone['maximum_distance_km'],
                    'estimated_delivery_minutes' => $zone['estimated_delivery_minutes'],
                    'priority' => $zone['priority'],
                    'is_active' => true,
                    'accepts_orders' => true,
                ]
            );
        }
    }
}
