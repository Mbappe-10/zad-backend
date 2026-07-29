<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    protected $fillable = ['user_id', 'event', 'ip_address', 'user_agent', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
