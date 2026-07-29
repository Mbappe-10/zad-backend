<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'decimal:4', 'minimum_amount' => 'decimal:2', 'maximum_amount' => 'decimal:2', 'is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
