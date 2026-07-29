<?php

namespace App\Services\Registration;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DynamicRegistrationValidator
{
    public function validateFamily(array $payload): array
    {
        $settings = $this->group('registration');

        return $this->validatePayload($payload, [
            'family_name' => [
                'required',
                'string',
                'min:'.($settings['familyNameMin'] ?? 3),
                'max:'.($settings['familyNameMax'] ?? 40),
            ],
            'manager_name' => [
                'required',
                'string',
                'min:'.($settings['familyManagerMin'] ?? 3),
                'max:'.($settings['familyManagerMax'] ?? 40),
            ],
            'phone' => ['required', 'digits:10'],
            'email' => [
                ($settings['familyEmailRequired'] ?? true) ? 'required' : 'nullable',
                'email:rfc,dns',
                'max:190',
            ],
            'agreement_accepted' => ['accepted'],
            'agreement_version' => ['required', 'string', 'max:30'],
        ], $settings);
    }

    public function validateDriver(array $payload): array
    {
        $settings = $this->group('registration');
        $vehicle = $payload['vehicle_type'] ?? null;

        $rules = [
            'name' => [
                'required',
                'string',
                'min:'.($settings['driverNameMin'] ?? 3),
                'max:'.($settings['driverNameMax'] ?? 40),
            ],
            'phone' => ['required', 'digits:'.($settings['driverPhoneLength'] ?? 10)],
            'vehicle_type' => ['required', 'in:scooter,motorcycle,car'],
            'identity_image' => [
                ($vehicle === 'scooter' && ($settings['scooterRequireIdentity'] ?? true))
                    ? 'required'
                    : 'nullable',
                'image',
                'max:'.(($settings['maximumImageMb'] ?? 5) * 1024),
            ],
            'vehicle_images' => ['required', 'array', 'max:10'],
            'vehicle_images.*' => ['image', 'max:5120'],
            'license_image' => [
                $this->licenseRequired($vehicle, $settings) ? 'required' : 'nullable',
                'image',
                'max:5120',
            ],
            'agreement_accepted' => ['accepted'],
            'agreement_version' => ['required', 'string', 'max:30'],
        ];

        $validated = $this->validatePayload($payload, $rules, $settings);

        if ($vehicle === 'scooter') {
            $expected = (int) ($settings['scooterImagesCount'] ?? 3);
            $actual = count($payload['vehicle_images'] ?? []);

            if ($actual !== $expected) {
                throw ValidationException::withMessages([
                    'vehicle_images' => ["يجب رفع {$expected} صور للسكوتر."],
                ]);
            }
        }

        return $validated;
    }

    private function validatePayload(array $payload, array $rules, array $settings): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if (strlen((string) $encoded) > ((int) ($settings['maximumRequestKb'] ?? 256) * 1024)) {
            throw ValidationException::withMessages([
                'request' => ['حجم بيانات التسجيل أكبر من الحد المسموح.'],
            ]);
        }

        $this->rejectSuspiciousStrings($payload, $settings);

        return Validator::make($payload, $rules)->validate();
    }

    private function rejectSuspiciousStrings(array $payload, array $settings): void
    {
        array_walk_recursive($payload, function ($value, $key) use ($settings): void {
            if (! is_string($value)) return;

            if (($settings['rejectHtml'] ?? true) && $value !== strip_tags($value)) {
                throw ValidationException::withMessages([$key => ['لا يسمح بإدخال HTML.']]);
            }

            if (($settings['rejectScripts'] ?? true) && preg_match('/<script|javascript:|onerror\s*=|onload\s*=/iu', $value)) {
                throw ValidationException::withMessages([$key => ['تم رفض محتوى غير آمن.']]);
            }

            if (($settings['rejectSqlPatterns'] ?? true) && preg_match('/(\bunion\b\s+\bselect\b|\bdrop\b\s+\btable\b|--|\/\*)/iu', $value)) {
                throw ValidationException::withMessages([$key => ['تم رفض نمط إدخال مشبوه.']]);
            }
        });
    }

    private function licenseRequired(?string $vehicle, array $settings): bool
    {
        return match ($vehicle) {
            'scooter' => (bool) ($settings['scooterRequireLicense'] ?? false),
            'motorcycle' => (bool) ($settings['motorcycleRequireLicense'] ?? true),
            'car' => (bool) ($settings['carRequireLicense'] ?? true),
            default => false,
        };
    }

    private function group(string $group): array
    {
        return PlatformSetting::query()
            ->where('group', $group)
            ->get()
            ->mapWithKeys(fn (PlatformSetting $item) => [
                $item->key => $item->value['value'] ?? null,
            ])
            ->all();
    }
}
