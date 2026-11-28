<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierSupplier extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'payment_days' => 'integer'];
    }
}
