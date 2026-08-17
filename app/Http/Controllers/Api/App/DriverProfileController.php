<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverProfileFieldSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DriverProfileController extends Controller
{
    private const TYPES = ['scooter', 'motorcycle', 'car'];

    private const DOCUMENTS = [
        'scooter' => ['identity_photo', 'profile_photo', 'scooter_front', 'scooter_rear', 'delivery_box', 'helmet_photo'],
        'motorcycle' => ['identity_photo', 'profile_photo', 'motorcycle_license', 'motorcycle_photo', 'delivery_box', 'helmet_photo'],
        'car' => ['identity_photo', 'profile_photo', 'driving_license', 'vehicle_registration', 'cargo_interior'],
    ];

    public function fields(): JsonResponse
    {
        return response()->json(['data' => [
            'vehicle_types' => self::TYPES,
            'required_documents' => self::DOCUMENTS,
            'extra_fields' => DriverProfileFieldSetting::query()->where('is_active', true)->orderBy('sort_order')->get(),
        ]]);
    }

    public function show(Request $request): JsonResponse
    {
        $profile = AppProfile::query()->where('user_id', $request->user()->id)->firstOrFail();
        $driver = $profile->driver()->with('documents')->first();
        return response()->json(['data' => $driver]);
    }

    public function store(Request $request): JsonResponse
    {
        $vehicleType = (string) $request->input('vehicle_type');
        $rules = [
            'name' => ['required', 'string', 'max:180'],
            'identity_number' => ['required', 'string', 'max:30'],
            'phone' => ['required', 'string', 'max:30'],
            'emergency_phone' => ['nullable', 'string', 'max:30'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'vehicle_type' => ['required', Rule::in(self::TYPES)],
            'plate_number' => [Rule::requiredIf(in_array($vehicleType, ['motorcycle', 'car'], true)), 'nullable', 'string', 'max:30'],
            'license_number' => [Rule::requiredIf($vehicleType === 'car'), 'nullable', 'string', 'max:100'],
            'answers' => ['nullable', 'array'],
        ];

        foreach (self::DOCUMENTS[$vehicleType] ?? [] as $type) {
            $rules["documents.$type"] = ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'];
        }

        $data = $request->validate($rules);
        $this->validateDynamicAnswers($request, $vehicleType);

        $profile = AppProfile::query()->firstOrCreate(['user_id' => $request->user()->id], ['roles' => ['driver'], 'active_mode' => 'driver']);
        $driver = DB::transaction(function () use ($request, $data, $profile, $vehicleType): Driver {
            $driver = Driver::query()->updateOrCreate(
                ['user_id' => $request->user()->id],
                [
                    'code' => $profile->driver?->code ?? 'DRV-'.Str::upper(Str::random(8)),
                    'name' => trim($data['name']), 'phone' => trim($data['phone']),
                    'emergency_phone' => $data['emergency_phone'] ?? null,
                    'identity_number' => trim($data['identity_number']), 'city_id' => $data['city_id'],
                    'vehicle_type' => $vehicleType, 'plate_number' => $data['plate_number'] ?? null,
                    'license_number' => $data['license_number'] ?? null,
                    'status' => 'pending', 'application_status' => 'pending', 'is_online' => false,
                    'submitted_at' => now(), 'rejection_reason' => null,
                    'metadata' => array_merge((array) ($profile->driver?->metadata ?? []), ['custom_fields' => $data['answers'] ?? []]),
                ],
            );

            foreach (self::DOCUMENTS[$vehicleType] as $type) {
                $file = $request->file("documents.$type");
                $old = DriverDocument::query()->where('driver_id', $driver->id)->where('type', $type)->first();
                $path = $file->store("drivers/{$driver->id}/documents", 'public');
                if ($old?->path) Storage::disk('public')->delete($old->path);
                DriverDocument::query()->updateOrCreate(['driver_id' => $driver->id, 'type' => $type], ['path' => $path, 'status' => 'pending', 'rejection_reason' => null]);
            }

            $profile->update(['driver_id' => $driver->id, 'active_mode' => 'driver', 'roles' => array_values(array_unique([...($profile->roles ?? []), 'driver']))]);
            return $driver->load('documents');
        });

        return response()->json(['message' => 'تم إرسال بيانات المندوب للمراجعة.', 'data' => $driver], 201);
    }

    private function validateDynamicAnswers(Request $request, string $vehicleType): void
    {
        $answers = (array) $request->input('answers', []);
        $fields = DriverProfileFieldSetting::query()->where('is_active', true)->orderBy('sort_order')->get();
        $errors = [];
        foreach ($fields as $field) {
            $vehicles = $field->vehicle_types ?? [];
            if ($vehicles !== [] && ! in_array($vehicleType, $vehicles, true)) continue;
            $value = $answers[$field->key] ?? null;
            if ($field->is_required && ($value === null || trim((string) $value) === '')) $errors["answers.{$field->key}"] = ["حقل {$field->label_ar} مطلوب."];
            if ($value !== null && $field->field_type === 'number' && ! is_numeric($value)) $errors["answers.{$field->key}"] = ["حقل {$field->label_ar} يجب أن يكون رقمًا."];
            if ($value !== null && $field->field_type === 'date' && strtotime((string) $value) === false) $errors["answers.{$field->key}"] = ["حقل {$field->label_ar} يجب أن يكون تاريخًا صحيحًا."];
        }
        if ($errors !== []) throw ValidationException::withMessages($errors);
    }
}