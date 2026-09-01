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

    /**
     * أربع صور للمركبة. في السكوتر والدباب يجب أن تُظهر صورة الخلف الصندوق.
     */
    private const DOCUMENTS = [
        'scooter' => [
            'identity_photo',
            'scooter_front',
            'scooter_rear_box',
            'scooter_right',
            'scooter_left',
        ],
        'motorcycle' => [
            'identity_photo',
            'motorcycle_license',
            'vehicle_registration',
            'motorcycle_front',
            'motorcycle_rear_box',
            'motorcycle_right',
            'motorcycle_left',
        ],
        'car' => [
            'identity_photo',
            'driving_license',
            'vehicle_registration',
            'car_exterior',
            'cargo_interior',
        ],
    ];

    public function fields(): JsonResponse
    {
        $fields = DriverProfileFieldSetting::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => [
                'vehicle_types' => self::TYPES,
                'required_documents' => self::DOCUMENTS,
                'fields' => $fields,
                'extra_fields' => $fields,
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $profile = AppProfile::query()
            ->where('user_id', $request->user()->id)
            ->first();

        if ($profile === null || $profile->driver_id === null) {
            return response()->json([
                'data' => null,
                'next_step' => 'complete_driver_profile',
            ]);
        }

        $driver = Driver::query()
            ->with(['documents' => fn ($query) => $query->latest(), 'city'])
            ->find($profile->driver_id);

        if ($driver === null) {
            return response()->json([
                'data' => null,
                'next_step' => 'complete_driver_profile',
            ]);
        }

        return response()->json([
            'data' => $driver,
            'next_step' => $this->nextStep($driver),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->has('answers') && $request->has('extra_answers')) {
            $request->merge(['answers' => $request->input('extra_answers', [])]);
        }

        $vehicleType = trim((string) $request->input('vehicle_type'));
        $boxRequired = in_array($vehicleType, ['scooter', 'motorcycle'], true);

        $rules = [
            'name' => ['required', 'string', 'max:180'],
            'identity_number' => ['required', 'string', 'max:30'],
            'phone' => ['required', 'string', 'max:30'],
            'city' => ['required', 'string', 'max:120'],
            'vehicle_type' => ['required', Rule::in(self::TYPES)],
            'vehicle_model' => [
                Rule::requiredIf(in_array($vehicleType, ['scooter', 'car'], true)),
                'nullable',
                'string',
                'max:120',
            ],
            'plate_number' => [
                Rule::requiredIf(in_array($vehicleType, ['motorcycle', 'car'], true)),
                'nullable',
                'string',
                'max:30',
            ],
            'has_delivery_box' => [
                Rule::requiredIf($boxRequired),
                'nullable',
                'boolean',
            ],
            'answers' => ['nullable', 'array'],
        ];

        foreach ($this->requiredDocuments($vehicleType) as $documentType) {
            $rules["documents.$documentType"] = [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:8192',
            ];
        }

        $data = $request->validate($rules);

        if ($boxRequired && ! (bool) ($data['has_delivery_box'] ?? false)) {
            throw ValidationException::withMessages([
                'has_delivery_box' => ['وجود صندوق توصيل مثبت شرط لقبول السكوتر أو الدباب.'],
            ]);
        }

        $this->validateDynamicAnswers($request, $vehicleType);

        $profile = AppProfile::query()->firstOrCreate(
            ['user_id' => $request->user()->id],
            ['roles' => ['driver'], 'active_mode' => 'driver'],
        );

        $driver = DB::transaction(function () use (
            $request,
            $data,
            $profile,
            $vehicleType,
            $boxRequired,
        ): Driver {
            $existingDriver = Driver::query()
                ->where('user_id', $request->user()->id)
                ->first();

            $metadata = is_array($existingDriver?->metadata)
                ? $existingDriver->metadata
                : [];

            $metadata = array_merge($metadata, [
                'city' => trim($data['city']),
                'vehicle_model' => isset($data['vehicle_model'])
                    ? trim((string) $data['vehicle_model'])
                    : null,
                'has_delivery_box' => $boxRequired,
                'custom_fields' => $data['answers'] ?? [],
            ]);

            $driver = Driver::query()->updateOrCreate(
                ['user_id' => $request->user()->id],
                [
                    'code' => $existingDriver?->code ?? 'DRV-'.Str::upper(Str::random(8)),
                    'name' => trim($data['name']),
                    'phone' => trim($data['phone']),
                    'identity_number' => trim($data['identity_number']),
                    'vehicle_type' => $vehicleType,
                    'plate_number' => isset($data['plate_number'])
                        ? trim((string) $data['plate_number'])
                        : null,
                    'license_number' => null,
                    'status' => 'pending',
                    'application_status' => 'pending',
                    'is_online' => false,
                    'submitted_at' => now(),
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                    'rejection_reason' => null,
                    'metadata' => $metadata,
                ],
            );

            $requiredDocuments = $this->requiredDocuments($vehicleType);

            foreach ($requiredDocuments as $documentType) {
                $file = $request->file("documents.$documentType");

                if ($file === null) {
                    continue;
                }

                $oldDocument = DriverDocument::query()
                    ->where('driver_id', $driver->id)
                    ->where('type', $documentType)
                    ->first();

                $path = $file->store("drivers/{$driver->id}/documents", 'public');

                if ($oldDocument?->path && Storage::disk('public')->exists($oldDocument->path)) {
                    Storage::disk('public')->delete($oldDocument->path);
                }

                DriverDocument::query()->updateOrCreate(
                    ['driver_id' => $driver->id, 'type' => $documentType],
                    ['path' => $path, 'status' => 'pending', 'rejection_reason' => null],
                );
            }

            $obsoleteDocuments = DriverDocument::query()
                ->where('driver_id', $driver->id)
                ->whereNotIn('type', $requiredDocuments)
                ->get();

            foreach ($obsoleteDocuments as $obsoleteDocument) {
                if (
                    $obsoleteDocument->path
                    && Storage::disk('public')->exists($obsoleteDocument->path)
                ) {
                    Storage::disk('public')->delete($obsoleteDocument->path);
                }

                $obsoleteDocument->delete();
            }

            $roles = is_array($profile->roles) ? $profile->roles : [];
            $roles[] = 'driver';

            $profile->update([
                'driver_id' => $driver->id,
                'active_mode' => 'driver',
                'roles' => array_values(array_unique($roles)),
            ]);

            return $driver->load(['documents', 'city']);
        });

        return response()->json([
            'message' => 'تم إرسال بيانات المندوب للمراجعة.',
            'data' => $driver,
            'next_step' => 'driver_pending_review',
        ], 201);
    }

    private function requiredDocuments(string $vehicleType): array
    {
        return self::DOCUMENTS[$vehicleType] ?? [];
    }

    private function validateDynamicAnswers(Request $request, string $vehicleType): void
    {
        $answers = (array) $request->input('answers', []);
        $fields = DriverProfileFieldSetting::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        $errors = [];

        foreach ($fields as $field) {
            $vehicles = is_array($field->vehicle_types) ? $field->vehicle_types : [];

            if ($vehicles !== [] && ! in_array($vehicleType, $vehicles, true)) {
                continue;
            }

            $value = $answers[$field->key] ?? null;

            if ($field->is_required && ($value === null || (is_string($value) && trim($value) === ''))) {
                $errors["answers.{$field->key}"] = ["حقل {$field->label_ar} مطلوب."];
                continue;
            }

            if ($value !== null && $value !== '' && $field->field_type === 'number' && ! is_numeric($value)) {
                $errors["answers.{$field->key}"] = ["حقل {$field->label_ar} يجب أن يكون رقمًا."];
            }

            if ($value !== null && $value !== '' && $field->field_type === 'date' && strtotime((string) $value) === false) {
                $errors["answers.{$field->key}"] = ["حقل {$field->label_ar} يجب أن يكون تاريخًا صحيحًا."];
            }

            if ($value !== null && $value !== '' && $field->field_type === 'select') {
                $options = is_array($field->options) ? $field->options : [];

                if ($options !== [] && ! in_array($value, $options, true)) {
                    $errors["answers.{$field->key}"] = ["القيمة المختارة لحقل {$field->label_ar} غير صحيحة."];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function nextStep(Driver $driver): string
    {
        return match ($driver->application_status) {
            'approved' => 'dashboard',
            'rejected', 'needs_correction' => 'driver_profile_rejected',
            default => 'driver_pending_review',
        };
    }
}
