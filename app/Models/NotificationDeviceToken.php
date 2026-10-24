<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationDeviceToken extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_ciphertext', 'token_hash', 'device_id_hash'];

    protected function casts(): array
    {
        return [
            'token_ciphertext' => 'encrypted',
            'permission_granted' => 'boolean',
            'push_enabled' => 'boolean',
            'marketing_enabled' => 'boolean',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
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

    public function attempts(): HasMany
    {
        return $this->hasMany(NotificationPushAttempt::class, 'device_token_id');
    }

    public function isEligibleFor(string $type): bool
    {
        return $this->revoked_at === null
            && $this->permission_granted
            && $this->push_enabled
            && ($type !== 'marketing' || $this->marketing_enabled);
    }
}
