<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSettingAudit extends Model
{
    protected $fillable = [
        'user_id',
        'group',
        'key',
        'old_value',
        'new_value',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
        ];
    }
}
