<?php

namespace Database\Seeders;

use App\Models\ProductFieldSetting;
use Illuminate\Database\Seeder;

class ProductFieldSettingSeeder extends Seeder
{
    public function run(): void
    {
        $fields = [
            [
                'field_key' => 'name_ar',
                'label_ar' => 'اسم المنتج بالعربية',
                'label_en' => 'Arabic product name',
                'is_enabled' => true,
                'is_required' => true,
                'family_visible' => true,
                'family_editable' => true,
                'owner_only' => false,
                'sort_order' => 10,
            ],
            [
                'field_key' => 'name_en',
                'label_ar' => 'اسم المنتج بالإنجليزية',
                'label_en' => 'English product name',
                'is_enabled' => false,
                'is_required' => false,
                'family_visible' => false,
                'family_editable' => false,
                'owner_only' => false,
                'sort_order' => 20,
            ],
            [
                'field_key' => 'sku',
                'label_ar' => 'رمز المنتج',
                'label_en' => 'SKU',
                'is_enabled' => true,
                'is_required' => false,
                'family_visible' => false,
                'family_editable' => false,
                'owner_only' => true,
                'sort_order' => 30,
            ],
            [
                'field_key' => 'store_id',
                'label_ar' => 'المتجر',
                'label_en' => 'Store',
                'is_enabled' => true,
                'is_required' => true,
                'family_visible' => true,
                'family_editable' => false,
                'owner_only' => false,
                'sort_order' => 40,
            ],
            [
                'field_key' => 'category_id',
                'label_ar' => 'التصنيف',
                'label_en' => 'Category',
                'is_enabled' => true,
                'is_required' => false,
                'family_visible' => true,
                'family_editable' => true,
                'owner_only' => false,
                'sort_order' => 50,
            ],
            [
                'field_key' => 'price',
                'label_ar' => 'السعر',
                'label_en' => 'Price',
                'is_enabled' => true,
                'is_required' => true,
                'family_visible' => true,
                'family_editable' => true,
                'owner_only' => false,
                'sort_order' => 60,
            ],
            [
                'field_key' => 'compare_at_price',
                'label_ar' => 'السعر قبل الخصم',
                'label_en' => 'Compare-at price',
                'is_enabled' => true,
                'is_required' => false,
                'family_visible' => true,
                'family_editable' => true,
                'owner_only' => false,
                'sort_order' => 70,
            ],
            [
                'field_key' => 'preparation_minutes',
                'label_ar' => 'مدة التجهيز بالدقائق',
                'label_en' => 'Preparation time',
                'is_enabled' => true,
                'is_required' => false,
                'family_visible' => true,
                'family_editable' => true,
                'owner_only' => false,
                'sort_order' => 80,
            ],
            [
                'field_key' => 'image',
                'label_ar' => 'صورة المنتج',
                'label_en' => 'Product image',
                'is_enabled' => true,
                'is_required' => true,
                'family_visible' => true,
                'family_editable' => true,
                'owner_only' => false,
                'sort_order' => 90,
                'options' => [
                    'max_source_mb' => 10,
                    'target_size_kb' => 500,
                    'allowed_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
                    'single_image' => true,
                ],
            ],
            [
                'field_key' => 'description_ar',
                'label_ar' => 'الوصف بالعربية',
                'label_en' => 'Arabic description',
                'is_enabled' => true,
                'is_required' => false,
                'family_visible' => true,
                'family_editable' => true,
                'owner_only' => false,
                'sort_order' => 100,
            ],
            [
                'field_key' => 'description_en',
                'label_ar' => 'الوصف بالإنجليزية',
                'label_en' => 'English description',
                'is_enabled' => false,
                'is_required' => false,
                'family_visible' => false,
                'family_editable' => false,
                'owner_only' => false,
                'sort_order' => 110,
            ],
            [
                'field_key' => 'status',
                'label_ar' => 'حالة المنتج',
                'label_en' => 'Product status',
                'is_enabled' => true,
                'is_required' => true,
                'family_visible' => true,
                'family_editable' => false,
                'owner_only' => true,
                'sort_order' => 120,
                'options' => [
                    'default' => 'draft',
                    'allowed_values' => [
                        'draft',
                        'pending',
                        'active',
                        'inactive',
                        'approved',
                        'archived',
                        'out_of_stock',
                    ],
                ],
            ],
            [
                'field_key' => 'is_available',
                'label_ar' => 'التوفر للطلب',
                'label_en' => 'Order availability',
                'is_enabled' => true,
                'is_required' => true,
                'family_visible' => true,
                'family_editable' => true,
                'owner_only' => false,
                'sort_order' => 130,
                'options' => [
                    'default' => true,
                ],
            ],
        ];

        foreach ($fields as $field) {
            ProductFieldSetting::updateOrCreate(
                ['field_key' => $field['field_key']],
                $field,
            );
        }
    }
}