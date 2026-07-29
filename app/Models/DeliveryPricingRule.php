<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryPricingRule extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['minimum_fee' => 'decimal:2', 'base_fee' => 'decimal:2', 'per_km_fee' => 'decimal:2', 'surge_multiplier' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
