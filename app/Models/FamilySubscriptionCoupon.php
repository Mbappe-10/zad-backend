<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A discount that only applies to family store plan purchases, never to orders. */
class FamilySubscriptionCoupon extends Model
{
    protected $fillable = [
        'code', 'name', 'discount_type', 'discount_value', 'plan_codes',
        'max_redemptions', 'per_family_limit', 'redeemed_count',
        'starts_at', 'ends_at', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'plan_codes' => 'array',
            'max_redemptions' => 'integer',
            'per_family_limit' => 'integer',
            'redeemed_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(FamilySubscriptionCouponRedemption::class, 'coupon_id');
    }
}
