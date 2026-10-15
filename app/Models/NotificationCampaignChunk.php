<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationCampaignChunk extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(NotificationCampaign::class, 'campaign_id');
    }
}
