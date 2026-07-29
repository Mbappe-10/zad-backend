<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderCommission extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['base_amount' => 'decimal:2', 'commission_amount' => 'decimal:2', 'released_at' => 'datetime'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
