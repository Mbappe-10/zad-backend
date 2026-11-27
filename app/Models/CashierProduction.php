<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierProduction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['materials' => 'array', 'unit_cost' => 'float', 'total_cost' => 'float'];
    }
}
