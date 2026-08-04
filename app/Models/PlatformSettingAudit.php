<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSettingAudit extends Model
{
    protected $fillable = [
        'user_id',
        'group',
        'key',
        'old_value',
        'new_value',
        'status',
        'action',
        'reason',
        'ip_address',
        'user_agent',
        'approved_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}