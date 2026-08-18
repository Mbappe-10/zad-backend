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
    /*
    |--------------------------------------------------------------------------
    | أنواع المركبات
    |--------------------------------------------------------------------------
    */

    private const TYPES = [
        'scooter',
        'motorcycle',
        'car',
    ];

    /*
    |--------------------------------------------------------------------------
    | المستندات المطلوبة لكل مركبة
    |--------------------------------------------------------------------------
    |
    | السكوتر:
    | - الهوية
    | - صورة المندوب بالخوذة
    | - السكوتر من الأمام والخلف
    | - صندوق التوصيل
    |
    | الدباب:
    | - الهوية
    | - صورة المندوب بالخوذة
    | - رخصة الدباب
    | - صورة الدباب
    | - صندوق التوصيل
    |
    | السيارة:
    | - الهوية
    | - صورة شخصية واضحة
    | - رخصة القيادة
    | - مكان حفظ الطلب داخل السيارة
    |
    */

    private const DOCUMENTS = [
        'scooter' => [
            'identity_photo',
            'helmet_photo',
            'scooter_front',
            'scooter_rear',
            'delivery_box',
        ],

        'motorcycle' => [
            'identity_photo',
            'helmet_photo',
            'motorcycle_license',
            'motorcycle_photo',
            'delivery_box',
        ],

        'car' => [
            'identity_photo',
            'profile_photo',
            'driving_license',
            'cargo_interior',
        ],
    ];

    /*
    |--------------------------------------------------------------------------
    | الحقول والمستندات المطلوبة
    |--------------------------------------------------------------------------
    */

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

                // الاسم الذي يستخدمه تطبيق Flutter الجديد.
                'fields' => $fields,

                // للتوافق مع أي واجهة قديمة.
                'extra_fields' => $fields,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | عرض ملف المندوب الحالي
    |--------------------------------------------------------------------------
    */

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
            ->with([
                'documents' => function ($query): void {
                    $query->latest();
                },
                'city',
            ])
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

    /*
    |--------------------------------------------------------------------------
    | إنشاء ملف المندوب أو إعادة إرساله
    |--------------------------------------------------------------------------
    */

    public function store(Request $request): JsonResponse
    {
        /*
         * تطبيق Flutter الجديد قد يرسل الحقول الإضافية باسم
         * extra_answers، بينما بعض الواجهات القديمة تستخدم answers.
         * نوحّد الاسمين قبل التحقق.
         */
        if (
            ! $request->has('answers')
            && $request->has('extra_answers')
        ) {
            $request->merge([
                'answers' => $request->input(
                    'extra_answers',
                    [],
                ),
            ]);
        }

        $vehicleType = trim(
            (string) $request->input('vehicle_type'),
        );

        $rules = [
            'name' => [
                'required',
                'string',
                'max:180',
            ],

            'identity_number' => [
                'required',
                'string',
                'max:30',
            ],

            'phone' => [
                'required',
                'string',
                'max:30',
            ],

            'emergency_phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'city_id' => [
                'required',
                'integer',
                'exists:cities,id',
            ],

            'vehicle_type' => [
                'required',
                Rule::in(self::TYPES),
            ],

            /*
             * رقم اللوحة مطلوب للدباب والسيارة فقط.
             * السكوتر لا يحتاج رقم لوحة.
             */
            'plate_number' => [
                Rule::requiredIf(
                    in_array(
                        $vehicleType,
                        ['motorcycle', 'car'],
                        true,
                    ),
                ),
                'nullable',
                'string',
                'max:30',
            ],

            /*
             * رقم الرخصة الكتابي اختياري.
             * صورة الرخصة إلزامية حسب نوع المركبة.
             */
            'license_number' => [
                'nullable',
                'string',
                'max:100',
            ],

            'answers' => [
                'nullable',
                'array',
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | التحقق من الصور المطلوبة حسب المركبة
        |--------------------------------------------------------------------------
        */

        foreach (
            $this->requiredDocuments($vehicleType)
            as $documentType
        ) {
            $rules["documents.$documentType"] = [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:8192',
            ];
        }

        $data = $request->validate($rules);

        $this->validateDynamicAnswers(
            $request,
            $vehicleType,
        );

        $profile = AppProfile::query()->firstOrCreate(
            [
                'user_id' => $request->user()->id,
            ],
            [
                'roles' => ['driver'],
                'active_mode' => 'driver',
            ],
        );

        $driver = DB::transaction(
            function () use (
                $request,
                $data,
                $profile,
                $vehicleType
            ): Driver {
                $existingDriver = Driver::query()
                    ->where(
                        'user_id',
                        $request->user()->id,
                    )
                    ->first();

                $existingMetadata = is_array(
                    $existingDriver?->metadata,
                )
                    ? $existingDriver->metadata
                    : [];

                $driver = Driver::query()->updateOrCreate(
                    [
                        'user_id' => $request->user()->id,
                    ],
                    [
                        'code' => $existingDriver?->code
                            ?? 'DRV-'.Str::upper(
                                Str::random(8),
                            ),

                        'name' => trim($data['name']),

                        'phone' => trim($data['phone']),

                        'emergency_phone' => isset(
                            $data['emergency_phone'],
                        )
                            ? trim(
                                (string) $data[
                                    'emergency_phone'
                                ],
                            )
                            : null,

                        'identity_number' => trim(
                            $data['identity_number'],
                        ),

                        'city_id' => (int) $data['city_id'],

                        'vehicle_type' => $vehicleType,

                        'plate_number' => isset(
                            $data['plate_number'],
                        )
                            ? trim(
                                (string) $data[
                                    'plate_number'
                                ],
                            )
                            : null,

                        'license_number' => isset(
                            $data['license_number'],
                        )
                            ? trim(
                                (string) $data[
                                    'license_number'
                                ],
                            )
                            : null,

                        /*
                         * بعد إرسال أو تعديل البيانات تعود الحالة
                         * للمراجعة ولا يصبح المندوب متاحًا مباشرة.
                         */
                        'status' => 'pending',

                        'application_status' => 'pending',

                        'is_online' => false,

                        'submitted_at' => now(),

                        'reviewed_at' => null,

                        'reviewed_by' => null,

                        'rejection_reason' => null,

                        'metadata' => array_merge(
                            $existingMetadata,
                            [
                                'custom_fields' =>
                                    $data['answers'] ?? [],

                                'last_submitted_vehicle_type' =>
                                    $vehicleType,
                            ],
                        ),
                    ],
                );

                /*
                |--------------------------------------------------------------------------
                | حفظ مستندات المندوب
                |--------------------------------------------------------------------------
                */

                foreach (
                    $this->requiredDocuments($vehicleType)
                    as $documentType
                ) {
                    $file = $request->file(
                        "documents.$documentType",
                    );

                    if ($file === null) {
                        continue;
                    }

                    $oldDocument = DriverDocument::query()
                        ->where(
                            'driver_id',
                            $driver->id,
                        )
                        ->where(
                            'type',
                            $documentType,
                        )
                        ->first();

                    $path = $file->store(
                        "drivers/{$driver->id}/documents",
                        'public',
                    );

                    if (
                        $oldDocument?->path
                        && Storage::disk('public')->exists(
                            $oldDocument->path,
                        )
                    ) {
                        Storage::disk('public')->delete(
                            $oldDocument->path,
                        );
                    }

                    DriverDocument::query()->updateOrCreate(
                        [
                            'driver_id' => $driver->id,
                            'type' => $documentType,
                        ],
                        [
                            'path' => $path,
                            'status' => 'pending',
                            'rejection_reason' => null,
                        ],
                    );
                }

                /*
                 * حذف المستندات التي تخص مركبة سابقة ولم تعد
                 * مطلوبة بعد تغيير نوع المركبة.
                 */
                $requiredDocuments = $this
                    ->requiredDocuments($vehicleType);

                $obsoleteDocuments = DriverDocument::query()
                    ->where('driver_id', $driver->id)
                    ->whereNotIn(
                        'type',
                        $requiredDocuments,
                    )
                    ->get();

                foreach (
                    $obsoleteDocuments
                    as $obsoleteDocument
                ) {
                    if (
                        $obsoleteDocument->path
                        && Storage::disk('public')->exists(
                            $obsoleteDocument->path,
                        )
                    ) {
                        Storage::disk('public')->delete(
                            $obsoleteDocument->path,
                        );
                    }

                    $obsoleteDocument->delete();
                }

                /*
                |--------------------------------------------------------------------------
                | ربط AppProfile بالمندوب
                |--------------------------------------------------------------------------
                */

                $roles = is_array($profile->roles)
                    ? $profile->roles
                    : [];

                if (! in_array('driver', $roles, true)) {
                    $roles[] = 'driver';
                }

                $profile->update([
                    'driver_id' => $driver->id,
                    'active_mode' => 'driver',
                    'roles' => array_values(
                        array_unique($roles),
                    ),
                ]);

                return $driver->load([
                    'documents',
                    'city',
                ]);
            },
        );

        return response()->json([
            'message' =>
                'تم إرسال بيانات المندوب للمراجعة.',

            'data' => $driver,

            'next_step' => 'driver_pending_review',
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | المستندات المطلوبة
    |--------------------------------------------------------------------------
    */

    private function requiredDocuments(
        string $vehicleType,
    ): array {
        return self::DOCUMENTS[$vehicleType] ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | التحقق من الأسئلة التي يضيفها المدير
    |--------------------------------------------------------------------------
    */

    private function validateDynamicAnswers(
        Request $request,
        string $vehicleType,
    ): void {
        $answers = (array) $request->input(
            'answers',
            [],
        );

        $fields = DriverProfileFieldSetting::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $errors = [];

        foreach ($fields as $field) {
            $vehicles = is_array($field->vehicle_types)
                ? $field->vehicle_types
                : [];

            if (
                $vehicles !== []
                && ! in_array(
                    $vehicleType,
                    $vehicles,
                    true,
                )
            ) {
                continue;
            }

            $value = $answers[$field->key] ?? null;

            if (
                $field->is_required
                && (
                    $value === null
                    || (
                        is_string($value)
                        && trim($value) === ''
                    )
                )
            ) {
                $errors["answers.{$field->key}"] = [
                    "حقل {$field->label_ar} مطلوب.",
                ];

                continue;
            }

            if (
                $value !== null
                && $value !== ''
                && $field->field_type === 'number'
                && ! is_numeric($value)
            ) {
                $errors["answers.{$field->key}"] = [
                    "حقل {$field->label_ar} يجب أن يكون رقمًا.",
                ];
            }

            if (
                $value !== null
                && $value !== ''
                && $field->field_type === 'date'
                && strtotime((string) $value) === false
            ) {
                $errors["answers.{$field->key}"] = [
                    "حقل {$field->label_ar} يجب أن يكون تاريخًا صحيحًا.",
                ];
            }

            if (
                $value !== null
                && $value !== ''
                && $field->field_type === 'select'
            ) {
                $options = is_array($field->options)
                    ? $field->options
                    : [];

                if (
                    $options !== []
                    && ! in_array(
                        $value,
                        $options,
                        true,
                    )
                ) {
                    $errors["answers.{$field->key}"] = [
                        "القيمة المختارة لحقل {$field->label_ar} غير صحيحة.",
                    ];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(
                $errors,
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | تحديد الخطوة التالية
    |--------------------------------------------------------------------------
    */

    private function nextStep(
        Driver $driver,
    ): string {
        return match ($driver->application_status) {
            'approved' => 'dashboard',

            'rejected',
            'needs_correction' =>
                'driver_profile_rejected',

            default => 'driver_pending_review',
        };
    }
}