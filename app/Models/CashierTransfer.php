<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierTransfer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['auto' => 'boolean', 'items' => 'array', 'done_at' => 'datetime'];
    }
}
