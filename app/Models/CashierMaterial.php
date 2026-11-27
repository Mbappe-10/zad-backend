<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierMaterial extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_cost' => 'float', 'low_alert' => 'float'];
    }
}
