<?php

namespace App\Models;

// ZAD_DELIVERY_OTP_V1
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRating extends Model
{
    protected $fillable = [
        'order_id',
        'customer_id',
        'guest_session_id',
        'driver_id',
        'store_id',
        'arrival_condition',
        'driver_score',
        'driver_tags',
        'food_quality_score',
        'cleanliness_packaging_score',
        'order_accuracy_score',
        'comment',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'driver_score' => 'integer',
            'driver_tags' => 'array',
            'food_quality_score' => 'integer',
            'cleanliness_packaging_score' => 'integer',
            'order_accuracy_score' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}