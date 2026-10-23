<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotificationCampaign extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'audience_ids' => 'array',
            'data' => 'array',
            'failure_summary' => 'array',
            'scheduled_at' => 'datetime',
            'queued_at' => 'datetime',
            'processing_at' => 'datetime',
            'audience_built_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class, 'campaign_id');
    }
}
