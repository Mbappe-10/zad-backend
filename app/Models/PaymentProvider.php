<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentProvider extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'settings' => 'array', 'fixed_fee' => 'decimal:2', 'percentage_fee' => 'decimal:4'];
    }
}
