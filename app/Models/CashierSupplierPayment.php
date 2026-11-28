<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierSupplierPayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'paid_at' => 'datetime'];
    }
}
