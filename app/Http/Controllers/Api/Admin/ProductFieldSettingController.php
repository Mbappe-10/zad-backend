<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductFieldSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductFieldSettingController extends Controller
{
    /**
     * الحقول التي لا يسمح بتعطيلها بالكامل؛
     * لأنها أساسية لتشغيل المنتج والنظام.
     */
    private const PROTECTED_FIELDS = [
        'name_ar',
        'store_id',
        'price',
        'status',
        'is_available',
    ];

    /**
     * عرض جميع إعدادات الحقول.
     *
     * هذا المسار متاح للمستخدم المصادق عليه حتى تستطيع صفحات
     * لوحة التحكم قراءة إعدادات الحقول، لكن meta يوضح هل يملك
     * المستخدم حق الإدارة والتعديل أم لا.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $fields = ProductFieldSetting::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $fields,
            'meta' => [
                'protected_fields' => self::PROTECTED_FIELDS,
                'is_platform_owner' => (bool) $user?->isPlatformOwner(),
                'can_manage' => (bool) $user?->canManageProductFields(),
                'can_view' => (bool) $user?->canViewProductFields(),
            ],
        ]);
    }

    /**
     * تحديث إعدادات مجموعة من الحقول.
     *
     * المالك يملك الصلاحية دائمًا.
     * أي موظف آخر يحتاج products.fields.manage.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->canManageProductFields()) {
            return response()->json([
                'message' => 'ليس لديك صلاحية لإدارة حقول المنتجات.',
            ], 403);
        }

        $validated = $request->validate([
            'fields' => [
                'required',
                'array',
                'min:1',
            ],

            'fields.*.field_key' => [
                'required',
                'string',
                'max:100',
                Rule::exists('product_field_settings', 'field_key'),
            ],

            'fields.*.label_ar' => [
                'sometimes',
                'required',
                'string',
                'max:150',
            ],

            'fields.*.label_en' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],

            'fields.*.is_enabled' => [
                'sometimes',
                'boolean',
            ],

            'fields.*.is_required' => [
                'sometimes',
                'boolean',
            ],

            'fields.*.family_visible' => [
                'sometimes',
                'boolean',
            ],

            'fields.*.family_editable' => [
                'sometimes',
                'boolean',
            ],

            'fields.*.owner_only' => [
                'sometimes',
                'boolean',
            ],

            'fields.*.sort_order' => [
                'sometimes',
                'integer',
                'min:0',
                'max:10000',
            ],

            'fields.*.options' => [
                'sometimes',
                'nullable',
                'array',
            ],
        ], [
            'fields.required' => 'يجب إرسال الحقول المطلوب تحديثها.',
            'fields.array' => 'صيغة الحقول غير صحيحة.',
            'fields.*.field_key.required' => 'مفتاح الحقل مطلوب.',
            'fields.*.field_key.exists' => 'أحد الحقول المرسلة غير موجود.',
        ]);

        $userId = $user->id;

        DB::transaction(function () use (
            $validated,
            $userId,
        ): void {
            foreach ($validated['fields'] as $fieldData) {
                $field = ProductFieldSetting::query()
                    ->where('field_key', $fieldData['field_key'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $updates = collect($fieldData)
                    ->except('field_key')
                    ->all();

                /*
                 * حماية الحقول الأساسية من التعطيل.
                 */
                if (
                    in_array(
                        $field->field_key,
                        self::PROTECTED_FIELDS,
                        true,
                    )
                ) {
                    $updates['is_enabled'] = true;
                }

                /*
                 * الحقل المعطل لا يكون مطلوبًا.
                 */
                if (
                    array_key_exists('is_enabled', $updates) &&
                    $updates['is_enabled'] === false
                ) {
                    $updates['is_required'] = false;
                }

                /*
                 * الحقل الخاص بالمالك لا يظهر أو يُعدّل بواسطة الأسرة.
                 */
                if (
                    array_key_exists('owner_only', $updates) &&
                    $updates['owner_only'] === true
                ) {
                    $updates['family_visible'] = false;
                    $updates['family_editable'] = false;
                }

                /*
                 * لا يمكن جعل الحقل قابلًا لتعديل الأسرة وهو غير ظاهر لها.
                 */
                $familyVisible = $updates['family_visible']
                    ?? $field->family_visible;

                if ($familyVisible === false) {
                    $updates['family_editable'] = false;
                }

                $updates['updated_by'] = $userId;

                $field->fill($updates)->save();
            }
        });

        return response()->json([
            'message' => 'تم تحديث إعدادات حقول المنتجات بنجاح.',
            'data' => ProductFieldSetting::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'meta' => [
                'protected_fields' => self::PROTECTED_FIELDS,
                'is_platform_owner' => true,
                'can_manage' => true,
                'can_view' => true,
            ],
        ]);
    }

    /**
     * إعدادات الحقول التي يحتاجها تطبيق الأسرة المنتجة.
     */
    public function familyFields(): JsonResponse
    {
        $fields = ProductFieldSetting::query()
            ->where('is_enabled', true)
            ->where('family_visible', true)
            ->where('owner_only', false)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'field_key',
                'label_ar',
                'label_en',
                'is_required',
                'family_editable',
                'sort_order',
                'options',
                'updated_at',
            ]);

        return response()->json([
            'data' => $fields,
        ]);
    }
}