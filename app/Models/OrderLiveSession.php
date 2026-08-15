<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLiveSession extends Model
{
    public const STATUS_WAITING = 'waiting';
    public const STATUS_LIVE = 'live';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ENDED = 'ended';

    protected $fillable = [
        'public_id',
        'order_id',
        'productive_family_id',
        'started_by_user_id',
        'room_name',
        'publisher_identity',
        'status',
        'viewer_count',
        'peak_viewers',
        'quality_profile',
        'compliance',
        'metadata',
        'final_photo_path',
        'final_photo_at',
        'started_at',
        'paused_at',
        'resumed_at',
        'last_heartbeat_at',
        'ended_at',
        'ended_reason',
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'productive_family_id' => 'integer',
            'started_by_user_id' => 'integer',
            'viewer_count' => 'integer',
            'peak_viewers' => 'integer',
            'compliance' => 'array',
            'metadata' => 'array',
            'final_photo_at' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'resumed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function productiveFamily(): BelongsTo
    {
        return $this->belongsTo(ProductiveFamily::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function isWatchable(): bool
    {
        return in_array(
            $this->status,
            [self::STATUS_LIVE, self::STATUS_PAUSED],
            true,
        );
    }
}