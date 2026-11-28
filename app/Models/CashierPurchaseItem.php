<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierPurchaseItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_cost' => 'float', 'total' => 'float'];
    }
}
