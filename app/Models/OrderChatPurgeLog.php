<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderChatPurgeLog extends Model
{
    public const MODE_AUTOMATIC = 'automatic';
    public const MODE_MANUAL = 'manual';

    protected $fillable = [
        'mode',
        'retention_days',
        'eligible_orders',
        'deleted_messages',
        'executed_by',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'eligible_orders' => 'integer',
            'deleted_messages' => 'integer',
            'executed_by' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'executed_by',
        );
    }
}