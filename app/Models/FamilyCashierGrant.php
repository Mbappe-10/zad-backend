<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FamilyCashierGrant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
