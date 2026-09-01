<?php

namespace App\Services\Admin;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VehiclePayloadNormalizer
{
    private const TYPES = ['scooter', 'motorcycle', 'car'];

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

    public static function normalize(array $payload): array
    {
        $type = trim((string) ($payload['vehicle_type'] ?? ''));
        $requiresBox = in_array($type, ['scooter', 'motorcycle'], true);
        $documents = self::DOCUMENTS[$type] ?? [];

        $rules = [
            'vehicle_type' => ['required', Rule::in(self::TYPES)],
            'driver_id' => ['nullable'],
            'driver_name' => ['nullable', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::in(['active', 'inactive', 'maintenance', 'suspended'])],
            'model' => [
                Rule::requiredIf(in_array($type, ['scooter', 'car'], true)),
                'nullable',
                'string',
                'max:120',
            ],
            'plate_number' => [
                Rule::requiredIf(in_array($type, ['motorcycle', 'car'], true)),
                'nullable',
                'string',
                'max:30',
            ],
            'has_delivery_box' => [
                Rule::requiredIf($requiresBox),
                'nullable',
                'boolean',
            ],
            'documents' => ['required', 'array'],
        ];

        foreach ($documents as $document) {
            $rules["documents.$document"] = ['required', 'string', 'max:1000'];
        }

        $validator = Validator::make($payload, $rules, [
            'city.required' => 'اكتب مدينة تشغيل المندوب.',
            'model.required' => 'موديل المركبة مطلوب لهذا النوع.',
            'plate_number.required' => 'رقم اللوحة مطلوب للدباب والسيارة.',
            'documents.required' => 'صور المركبة والوثائق مطلوبة.',
            'documents.*.required' => 'أكمل جميع الصور المطلوبة قبل الحفظ.',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        if ($requiresBox && ! (bool) ($payload['has_delivery_box'] ?? false)) {
            throw ValidationException::withMessages([
                'has_delivery_box' => ['وجود صندوق توصيل مثبت شرط للسكوتر والدباب.'],
            ]);
        }

        return [
            'vehicle_type' => $type,
            'driver_id' => $payload['driver_id'] ?? null,
            'driver_name' => self::text($payload['driver_name'] ?? null),
            'city' => self::text($payload['city'] ?? null),
            'status' => (string) ($payload['status'] ?? 'active'),
            'model' => self::text($payload['model'] ?? null),
            'plate_number' => self::text($payload['plate_number'] ?? null),
            'delivery_box_required' => $requiresBox,
            'has_delivery_box' => $requiresBox,
            'documents' => Arr::only((array) ($payload['documents'] ?? []), $documents),
            'notes' => self::text($payload['notes'] ?? null),

            // تصفير الحقول القديمة حتى لا تعود للظهور بعد تعديل السجل.
            'brand' => null,
            'year' => null,
            'color' => null,
            'city_id' => null,
            'box_capacity' => null,
            'max_distance_km' => null,
            'max_weight_kg' => null,
            'minimum_order_size' => null,
            'maximum_order_size' => null,
            'registration_number' => null,
            'registration_expiry' => null,
            'insurance_number' => null,
            'insurance_expiry' => null,
            'inspection_expiry' => null,
            'license_required' => in_array($type, ['motorcycle', 'car'], true),
        ];
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
