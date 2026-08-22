<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RolePortalRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'version' => 'integer',
            'effective_at' => 'datetime',
            'expires_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
