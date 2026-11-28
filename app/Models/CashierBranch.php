<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierBranch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_main' => 'boolean', 'active' => 'boolean'];
    }
}
