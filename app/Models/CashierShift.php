<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierShift extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
