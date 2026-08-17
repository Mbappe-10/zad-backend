<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverProfileFieldSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DriverProfileFieldController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DriverProfileFieldSetting::query()->orderBy('sort_order')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $field = DriverProfileFieldSetting::query()->create($this->validated($request));
        return response()->json(['message' => 'تمت إضافة السؤال.', 'data' => $field], 201);
    }

    public function update(Request $request, DriverProfileFieldSetting $field): JsonResponse
    {
        $field->update($this->validated($request, $field));
        return response()->json(['message' => 'تم تحديث السؤال.', 'data' => $field->fresh()]);
    }

    public function destroy(DriverProfileFieldSetting $field): JsonResponse
    {
        abort_if($field->is_system, 422, 'لا يمكن حذف حقل نظامي. يمكن تعطيله فقط.');
        $field->delete();
        return response()->json(['message' => 'تم حذف السؤال.']);
    }

    private function validated(Request $request, ?DriverProfileFieldSetting $field = null): array
    {
        return $request->validate([
            'key' => ['required', 'alpha_dash', 'max:80', Rule::unique('driver_profile_field_settings', 'key')->ignore($field?->id)],
            'label_ar' => ['required', 'string', 'max:180'], 'label_en' => ['nullable', 'string', 'max:180'],
            'field_type' => ['required', Rule::in(['text', 'number', 'date', 'select', 'textarea', 'boolean'])],
            'vehicle_types' => ['nullable', 'array'], 'vehicle_types.*' => [Rule::in(['scooter', 'motorcycle', 'car'])],
            'options' => ['nullable', 'array'], 'validation' => ['nullable', 'array'],
            'is_required' => ['required', 'boolean'], 'is_active' => ['required', 'boolean'], 'sort_order' => ['required', 'integer', 'min:0'],
        ]);
    }
}