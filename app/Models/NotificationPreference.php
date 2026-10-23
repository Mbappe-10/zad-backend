<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'in_app_enabled' => 'boolean',
            'push_enabled' => 'boolean',
            'marketing_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guestSession(): BelongsTo
    {
        return $this->belongsTo(AppGuestSession::class, 'guest_session_id');
    }
}
