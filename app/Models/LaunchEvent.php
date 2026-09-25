<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaunchEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
