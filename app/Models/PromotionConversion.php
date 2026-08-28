<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionConversion extends Model
{
    protected $fillable = [
        'promotion_id',
        'interaction_id',
        'order_id',
        'revenue',
        'coupon_code',
        'city_id',
        'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'promotion_id' => 'integer',
            'interaction_id' => 'integer',
            'order_id' => 'integer',
            'revenue' => 'decimal:2',
            'city_id' => 'integer',
            'converted_at' => 'datetime',
        ];
    }
}
