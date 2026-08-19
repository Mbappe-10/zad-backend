<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Driver extends Model
{
    use HasFactory, SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | حالات طلب الانضمام
    |--------------------------------------------------------------------------
    */

    public const APPLICATION_DRAFT = 'draft';
    public const APPLICATION_PENDING = 'pending';
    public const APPLICATION_APPROVED = 'approved';
    public const APPLICATION_REJECTED = 'rejected';

    /*
    |--------------------------------------------------------------------------
    | أنواع المركبات
    |--------------------------------------------------------------------------
    */

    public const VEHICLE_SCOOTER = 'scooter';
    public const VEHICLE_MOTORCYCLE = 'motorcycle';
    public const VEHICLE_CAR = 'car';

    protected $guarded = ['id'];

    protected $fillable = [
        'user_id',
        'city_id',
        'vehicle_id',

        'code',
        'name',
        'phone',
        'emergency_phone',

        'identity_number',
        'license_number',

        'vehicle_type',
        'plate_number',

        'status',
        'application_status',

        'is_online',
        'current_latitude',
        'current_longitude',
        'location_updated_at',
        'active_orders_count',
        'rating',

        'metadata',

        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
    ];

    /*
    |--------------------------------------------------------------------------
    | التحويلات
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'city_id' => 'integer',
            'vehicle_id' => 'integer',
            'reviewed_by' => 'integer',

            'is_online' => 'boolean',
            'active_orders_count' => 'integer',

            'current_latitude' => 'decimal:7',
            'current_longitude' => 'decimal:7',
            'rating' => 'decimal:2',

            'location_updated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',

            'metadata' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | المستخدم المرتبط
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | المدينة
    |--------------------------------------------------------------------------
    */

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /*
    |--------------------------------------------------------------------------
    | المركبة
    |--------------------------------------------------------------------------
    */

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /*
    |--------------------------------------------------------------------------
    | مستندات وصور المندوب
    |--------------------------------------------------------------------------
    */

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    /*
    |--------------------------------------------------------------------------
    | المسؤول الذي راجع الطلب
    |--------------------------------------------------------------------------
    */

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reviewed_by',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ملف المستخدم داخل التطبيق
    |--------------------------------------------------------------------------
    */

    public function appProfile(): HasOne
    {
        return $this->hasOne(
            AppProfile::class,
            'driver_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | فحص حالة طلب الانضمام
    |--------------------------------------------------------------------------
    */

    public function isApplicationDraft(): bool
    {
        return $this->application_status ===
            self::APPLICATION_DRAFT;
    }

    public function isApplicationPending(): bool
    {
        return $this->application_status ===
            self::APPLICATION_PENDING;
    }

    public function isApplicationApproved(): bool
    {
        return $this->application_status ===
            self::APPLICATION_APPROVED;
    }

    public function isApplicationRejected(): bool
    {
        return $this->application_status ===
            self::APPLICATION_REJECTED;
    }

    /*
    |--------------------------------------------------------------------------
    | أهلية استقبال الطلبات
    |--------------------------------------------------------------------------
    */

    public function canReceiveOrders(): bool
    {
        return $this->isApplicationApproved()
            && $this->status === 'active'
            && $this->is_online;
    }

    /*
    |--------------------------------------------------------------------------
    | أنواع المركبات المتاحة
    |--------------------------------------------------------------------------
    */

    public static function vehicleTypes(): array
    {
        return [
            self::VEHICLE_SCOOTER,
            self::VEHICLE_MOTORCYCLE,
            self::VEHICLE_CAR,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | حالات طلب الانضمام المتاحة
    |--------------------------------------------------------------------------
    */

    public static function applicationStatuses(): array
    {
        return [
            self::APPLICATION_DRAFT,
            self::APPLICATION_PENDING,
            self::APPLICATION_APPROVED,
            self::APPLICATION_REJECTED,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | المستندات المطلوبة حسب نوع المركبة
    |--------------------------------------------------------------------------
    */

    public static function requiredDocumentsFor(
        string $vehicleType,
    ): array {
        return match ($vehicleType) {
            /*
            | السكوتر:
            | هوية + صورة بالخوذة + أمام + خلف + صندوق.
            */
            self::VEHICLE_SCOOTER => [
                'identity_photo',
                'helmet_photo',
                'scooter_front',
                'scooter_rear',
                'delivery_box',
            ],

            /*
            | الدباب:
            | هوية + صورة بالخوذة + رخصة الدباب
            | + صورة الدباب + صندوق التوصيل.
            */
            self::VEHICLE_MOTORCYCLE => [
                'identity_photo',
                'helmet_photo',
                'motorcycle_license',
                'motorcycle_photo',
                'delivery_box',
            ],

            /*
            | السيارة:
            | هوية + صورة شخصية + رخصة القيادة
            | + مكان حفظ الطلب داخل السيارة.
            */
            self::VEHICLE_CAR => [
                'identity_photo',
                'profile_photo',
                'driving_license',
                'cargo_interior',
            ],

            default => [],
        };
    }
}