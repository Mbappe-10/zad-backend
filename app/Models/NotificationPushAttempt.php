<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPushAttempt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(NotificationRecipient::class, 'recipient_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NotificationDeviceToken::class, 'device_token_id');
    }
}
