<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierDevice extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'staff_token_hash'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'unlocked_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
