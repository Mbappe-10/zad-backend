<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryZone extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'city_id',
        'name_ar',
        'name_en',
        'code',
        'description_ar',
        'description_en',
        'center_latitude',
        'center_longitude',
        'polygon',
        'radius_km',
        'base_delivery_fee',
        'per_km_fee',
        'minimum_delivery_fee',
        'maximum_delivery_fee',
        'minimum_order_amount',
        'maximum_distance_km',
        'estimated_delivery_minutes',
        'priority',
        'is_active',
        'accepts_orders',
        'surge_enabled',
        'surge_multiplier',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'city_id' => 'integer',

            'center_latitude' => 'decimal:7',
            'center_longitude' => 'decimal:7',
            'polygon' => 'array',
            'radius_km' => 'decimal:2',

            'base_delivery_fee' => 'decimal:2',
            'per_km_fee' => 'decimal:2',
            'minimum_delivery_fee' => 'decimal:2',
            'maximum_delivery_fee' => 'decimal:2',
            'minimum_order_amount' => 'decimal:2',
            'maximum_distance_km' => 'decimal:2',

            'estimated_delivery_minutes' => 'integer',
            'priority' => 'integer',

            'is_active' => 'boolean',
            'accepts_orders' => 'boolean',
            'surge_enabled' => 'boolean',
            'surge_multiplier' => 'decimal:2',

            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeAcceptingOrders(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('accepts_orders', true);
    }

    public function scopeForCity(
        Builder $query,
        int $cityId
    ): Builder {
        return $query->where('city_id', $cityId);
    }

    public function scopeOrderedByPriority(Builder $query): Builder
    {
        return $query->orderBy('priority');
    }

    public function isAvailable(): bool
    {
        return $this->is_active && $this->accepts_orders;
    }

    public function calculateDeliveryFee(float $distanceKm): float
    {
        $calculatedFee =
            (float) $this->base_delivery_fee
            + ($distanceKm * (float) $this->per_km_fee);

        $calculatedFee = max(
            $calculatedFee,
            (float) $this->minimum_delivery_fee
        );

        if ($this->maximum_delivery_fee !== null) {
            $calculatedFee = min(
                $calculatedFee,
                (float) $this->maximum_delivery_fee
            );
        }

        if ($this->surge_enabled) {
            $calculatedFee *= (float) $this->surge_multiplier;
        }

        return round($calculatedFee, 2);
    }
}