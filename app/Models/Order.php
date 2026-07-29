<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory;
    use SoftDeletes;

    /*
    |--------------------------------------------------------------------------
    | Order Statuses
    |--------------------------------------------------------------------------
    */

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_READY = 'ready';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_PICKED_UP = 'picked_up';

    public const STATUS_DELIVERING = 'delivering';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REJECTED = 'rejected';

    /*
    |--------------------------------------------------------------------------
    | Payment Statuses
    |--------------------------------------------------------------------------
    */

    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_FAILED = 'failed';

    public const PAYMENT_REFUNDED = 'refunded';

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'number',
        'customer_id',
        'store_id',
        'driver_id',
        'city_id',
        'delivery_zone_id',
        'status',
        'payment_status',
        'subtotal',
        'delivery_fee',
        'discount',
        'tax',
        'total',
        'delivery_address',
        'delivery_distance_km',
        'delivery_latitude',
        'delivery_longitude',
        'notes',
        'accepted_at',
        'preparing_at',
        'ready_at',
        'picked_up_at',
        'delivered_at',
        'cancelled_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'store_id' => 'integer',
            'driver_id' => 'integer',
            'city_id' => 'integer',
            'delivery_zone_id' => 'integer',

            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',

            'delivery_distance_km' => 'decimal:2',
            'delivery_latitude' => 'decimal:7',
            'delivery_longitude' => 'decimal:7',
            'delivery_address' => 'array',

            'accepted_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('status', self::runningStatuses());
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereIn('status', self::completedStatuses());
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->whereIn('status', self::cancelledStatuses());
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeForCity(Builder $query, int $cityId): Builder
    {
        return $query->where('city_id', $cityId);
    }

    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeForDriver(Builder $query, int $driverId): Builder
    {
        return $query->where('driver_id', $driverId);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('payment_status', self::PAYMENT_PAID);
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('driver_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Status Helpers
    |--------------------------------------------------------------------------
    */

    public static function runningStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_ACCEPTED,
            self::STATUS_PREPARING,
            self::STATUS_READY,
            self::STATUS_ASSIGNED,
            self::STATUS_PICKED_UP,
            self::STATUS_DELIVERING,
        ];
    }

    public static function completedStatuses(): array
    {
        return [
            self::STATUS_DELIVERED,
            self::STATUS_COMPLETED,
        ];
    }

    public static function cancelledStatuses(): array
    {
        return [
            self::STATUS_CANCELLED,
            self::STATUS_REJECTED,
        ];
    }

    public static function availableStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_ACCEPTED,
            self::STATUS_PREPARING,
            self::STATUS_READY,
            self::STATUS_ASSIGNED,
            self::STATUS_PICKED_UP,
            self::STATUS_DELIVERING,
            self::STATUS_DELIVERED,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_REJECTED,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | State Checks
    |--------------------------------------------------------------------------
    */

    public function isRunning(): bool
    {
        return in_array($this->status, self::runningStatuses(), true);
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, self::completedStatuses(), true);
    }

    public function isCancelled(): bool
    {
        return in_array($this->status, self::cancelledStatuses(), true);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    public function isAssignable(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_ACCEPTED,
            self::STATUS_PREPARING,
            self::STATUS_READY,
            self::STATUS_ASSIGNED,
        ], true);
    }

    /*
    |--------------------------------------------------------------------------
    | Time Calculations
    |--------------------------------------------------------------------------
    */

    public function preparationMinutes(): ?int
    {
        if (! $this->accepted_at || ! $this->ready_at) {
            return null;
        }

        return (int) $this->accepted_at->diffInMinutes(
            $this->ready_at,
        );
    }

    public function deliveryMinutes(): ?int
    {
        if (! $this->picked_up_at || ! $this->delivered_at) {
            return null;
        }

        return (int) $this->picked_up_at->diffInMinutes(
            $this->delivered_at,
        );
    }

    public function totalProcessingMinutes(): ?int
    {
        if (! $this->created_at || ! $this->delivered_at) {
            return null;
        }

        return (int) $this->created_at->diffInMinutes(
            $this->delivered_at,
        );
    }
}
