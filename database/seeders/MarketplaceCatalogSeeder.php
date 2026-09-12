<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MarketplaceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name_ar' => 'الوجبات',
                'name_en' => 'Meals',
                'slug' => 'meals',
                'image_path' => 'icon:meals',
                'sort_order' => 1,
            ],
            [
                'name_ar' => 'الأكلات الجاهزة',
                'name_en' => 'Ready-made Food',
                'slug' => 'ready-made-food',
                'image_path' => 'icon:ready_food',
                'sort_order' => 2,
            ],
            [
                'name_ar' => 'المخبوزات',
                'name_en' => 'Bakery',
                'slug' => 'bakery',
                'image_path' => 'icon:bakery',
                'sort_order' => 3,
            ],
            [
                'name_ar' => 'الحلويات',
                'name_en' => 'Desserts',
                'slug' => 'desserts',
                'image_path' => 'icon:desserts',
                'sort_order' => 4,
            ],
            [
                'name_ar' => 'المشروبات',
                'name_en' => 'Beverages',
                'slug' => 'beverages',
                'image_path' => 'icon:beverages',
                'sort_order' => 5,
            ],
        ];

        DB::transaction(function () use ($categories): void {
            foreach ($categories as $item) {
                $category = Category::query()
                    ->withTrashed()
                    ->updateOrCreate(
                        ['slug' => $item['slug']],
                        [
                            'parent_id' => null,
                            'name_ar' => $item['name_ar'],
                            'name_en' => $item['name_en'],
                            'image_path' => $item['image_path'],
                            'sort_order' => $item['sort_order'],
                            'is_active' => true,
                        ],
                    );

                if ($category->trashed()) {
                    $category->restore();
                }
            }
        });
    }
}