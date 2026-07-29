<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformAuditLog extends Model
{
    protected $fillable = ['user_id', 'resource', 'record_id', 'action', 'before', 'after', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }
}
