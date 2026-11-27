<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FamilySubscriptionCouponRedemption extends Model
{
    protected $fillable = ['coupon_id', 'purchase_id', 'productive_family_id', 'discount_halalas', 'status'];

    protected function casts(): array
    {
        return ['discount_halalas' => 'integer'];
    }
}
