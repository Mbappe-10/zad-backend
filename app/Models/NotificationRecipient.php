<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NotificationRecipient extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'in_app_stored_at' => 'datetime',
            'opened_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(NotificationCampaign::class, 'campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guestSession(): BelongsTo
    {
        return $this->belongsTo(AppGuestSession::class, 'guest_session_id');
    }

    public function inAppNotification(): HasOne
    {
        return $this->hasOne(InAppNotification::class, 'recipient_id');
    }

    public function pushAttempts(): HasMany
    {
        return $this->hasMany(NotificationPushAttempt::class, 'recipient_id');
    }
}
