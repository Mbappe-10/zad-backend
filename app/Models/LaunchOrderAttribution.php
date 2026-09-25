<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaunchOrderAttribution extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'creator_commission_rate' => 'decimal:4',
            'creator_commission_amount' => 'decimal:2',
            'attributed_at' => 'datetime',
        ];
    }
}
