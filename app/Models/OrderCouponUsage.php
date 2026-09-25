<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCouponUsage extends Model
{
    protected $fillable = [
        'order_id',
        'customer_id',
        'platform_record_id',
        'coupon_code',
        'discount_type',
        'discount_value',
        'max_discount',
        'minimum_order',
        'subtotal_snapshot',
        'delivery_fee_snapshot',
        'discount_amount',
        'status',
        'coupon_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'customer_id' => 'integer',
            'platform_record_id' => 'integer',
            'discount_value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'minimum_order' => 'decimal:2',
            'subtotal_snapshot' => 'decimal:2',
            'delivery_fee_snapshot' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'coupon_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
