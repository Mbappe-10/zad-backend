<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class PhoneVerification extends Model
{
    public const STATUS_DISPATCHING = 'dispatching';

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $guarded = [];

    protected $hidden = [
        'code_hash',
        'request_ip_hash',
        'device_hash',
        'send_idempotency_key',
        'provider_message_id',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'code_length' => 'integer',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'resend_available_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_attempt_at' => 'datetime',
            'verified_at' => 'datetime',
            'verification_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guestSession(): BelongsTo
    {
        return $this->belongsTo(
            AppGuestSession::class,
            'guest_session_id',
        );
    }
}
