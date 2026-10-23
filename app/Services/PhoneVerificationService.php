<?php

namespace App\Services;

use App\Contracts\OtpDeliveryProvider;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Services\App\GuestSessionAuthenticator;
use App\Services\Notifications\TransactionalNotificationService;
use App\Services\PhoneVerification\PhoneVerificationActor;
use App\Services\PhoneVerification\PhoneVerificationException;
use App\Support\InternalTesting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class PhoneVerificationService
{
    private const CHALLENGE_TTL_SECONDS = 300;

    private const RESEND_COOLDOWN_SECONDS = 90;

    private const MAX_ATTEMPTS = 3;

    private const LOCKOUT_SECONDS = 900;

    private const MAX_DAILY_SENDS = 4;

    private const VERIFIED_DAYS = 10;

    private const CHECKOUT_TOKEN_MINUTES = 20;

    private const CODE_LENGTH = 6;

    public function __construct(
        private readonly OtpDeliveryProvider $provider,
        private readonly GuestSessionAuthenticator $guestAuthenticator,
        private readonly TransactionalNotificationService $notifications,
    ) {
    }

    public function resolveActor(
        Request $request,
        ?string $guestSessionId,
        ?string $deviceId = null,
        bool $requireGuest = true,
    ): PhoneVerificationActor {
        $user = Auth::guard('sanctum')->user();

        if ($user !== null && ! $user instanceof User) {
            $user = null;
        }

        if ($user !== null && ! $user->isActive()) {
            throw new PhoneVerificationException(
                'inactive_user',
                'The authenticated account is not active.',
                403,
            );
        }

        $guest = null;

        if (filled($guestSessionId)) {
            $guest = $this->guestAuthenticator->authenticate(
                (string) $guestSessionId,
                $this->guestAuthenticator->tokenFromRequest($request),
                $deviceId,
            );
        } elseif ($requireGuest) {
            throw new PhoneVerificationException(
                'guest_session_required',
                'A secure guest session is required.',
                422,
            );
        }

        $deviceBasis = $guest !== null
            ? trim((string) ($guest->device_id ?: $guest->getKey()))
            : '';

        return new PhoneVerificationActor(
            $user,
            $guest,
            $deviceBasis !== ''
                ? $this->secureHash('device|'.$deviceBasis)
                : null,
            $this->secureHash('ip|'.(string) $request->ip()),
        );
    }

    public function status(
        string $phone,
        string $purpose,
        PhoneVerificationActor $actor,
    ): array {
        $phone = $this->normalizePhone($phone);
        $verification = $this->findReusableVerification(
            $phone,
            $purpose,
            $actor,
        );

        if ($verification !== null) {
            return $this->verifiedPayload($verification, $actor);
        }

        return $this->unverifiedPayload($phone, $purpose, $actor);
    }

    public function send(
        string $phone,
        string $purpose,
        PhoneVerificationActor $actor,
        ?string $requestId = null,
    ): array {
        $phone = $this->normalizePhone($phone);
        $verified = $this->findReusableVerification(
            $phone,
            $purpose,
            $actor,
        );

        if ($verified !== null) {
            return [
                'http_status' => 200,
                ...$this->verifiedPayload($verified, $actor),
            ];
        }

        $idempotencyKey = $this->secureHash(implode('|', [
            'otp-send',
            $requestId ?: (string) Str::uuid(),
            $phone,
            $purpose,
            $actor->key(),
        ]));

        [$verification, $code, $wasReused] = DB::transaction(
            function () use (
                $phone,
                $purpose,
                $actor,
                $idempotencyKey,
            ): array {
                $this->lockDailyCounter($phone);
                $this->assertNotLocked($phone, $purpose, $actor);

                $idempotent = PhoneVerification::query()
                    ->where('send_idempotency_key', $idempotencyKey)
                    ->first();

                if ($idempotent !== null) {
                    return [$idempotent, null, true];
                }

                $pending = $this->pendingChallengeQuery(
                    $phone,
                    $purpose,
                    $actor,
                )
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if (
                    $pending !== null
                    && (
                        $pending->status === PhoneVerification::STATUS_DISPATCHING
                        || (
                            $pending->resend_available_at !== null
                            && now()->lessThan($pending->resend_available_at)
                        )
                    )
                ) {
                    return [$pending, null, true];
                }

                $globalCooldown = PhoneVerification::query()
                    ->where('phone', $phone)
                    ->where('purpose', $purpose)
                    ->whereIn('status', [
                        PhoneVerification::STATUS_DISPATCHING,
                        PhoneVerification::STATUS_PENDING,
                    ])
                    ->where('expires_at', '>', now())
                    ->where('resend_available_at', '>', now())
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($globalCooldown !== null) {
                    throw new PhoneVerificationException(
                        'phone_send_cooldown',
                        'Please wait before requesting another verification code.',
                        429,
                        [
                            'retry_after_seconds' => $this->secondsUntil(
                                $globalCooldown->resend_available_at,
                            ),
                        ],
                    );
                }

                $dailySends = $this->dailySends($phone);

                if ($dailySends >= self::MAX_DAILY_SENDS) {
                    throw new PhoneVerificationException(
                        'daily_send_limit',
                        'The daily verification-code limit has been reached.',
                        429,
                        [
                            'daily_sends_remaining' => 0,
                            'retry_after_seconds' => $this->secondsUntilNextRiyadhDay(),
                        ],
                    );
                }

                if ($pending !== null) {
                    $pending->forceFill([
                        'status' => PhoneVerification::STATUS_REVOKED,
                        'revoked_at' => now(),
                    ])->save();
                }

                $code = (string) random_int(100000, 999999);
                $now = now();
                $verification = PhoneVerification::query()->create([
                    'user_id' => $actor->userId(),
                    'guest_session_id' => $actor->guestId(),
                    'phone' => $phone,
                    'purpose' => $purpose,
                    'code_hash' => Hash::make($code),
                    'code_length' => self::CODE_LENGTH,
                    'attempts' => 0,
                    'status' => PhoneVerification::STATUS_DISPATCHING,
                    'expires_at' => $now->copy()->addSeconds(
                        self::CHALLENGE_TTL_SECONDS,
                    ),
                    'resend_available_at' => $now->copy()->addSeconds(
                        self::RESEND_COOLDOWN_SECONDS,
                    ),
                    'device_hash' => $actor->deviceHash,
                    'request_ip_hash' => $actor->ipHash,
                    'provider' => (string) config(
                        'phone_verification.provider',
                        'meta_whatsapp',
                    ),
                    'send_idempotency_key' => $idempotencyKey,
                ]);

                DB::table('phone_verification_send_counters')
                    ->where('phone_hash', $this->phoneHash($phone))
                    ->where('send_date', $this->riyadhDate())
                    ->increment('sends', 1, ['updated_at' => $now]);

                return [$verification, $code, false];
            },
            3,
        );

        if ($code === null) {
            if ($verification->status === PhoneVerification::STATUS_FAILED) {
                throw new PhoneVerificationException(
                    'otp_delivery_unavailable',
                    'The verification code could not be sent. Please try again later.',
                    503,
                    [
                        'retry_after_seconds' => $this->secondsUntil(
                            $verification->resend_available_at,
                        ),
                    ],
                );
            }

            return [
                'http_status' => 200,
                ...$this->challengePayload(
                    $verification,
                    $phone,
                    $wasReused,
                    false,
                ),
            ];
        }

        $delivery = $this->provider->sendAuthenticationCode(
            $phone,
            $code,
            $idempotencyKey,
        );

        if (! $delivery->accepted) {
            $verification->forceFill([
                'status' => PhoneVerification::STATUS_FAILED,
                'provider' => $delivery->provider,
                'failure_code' => $delivery->failureCode
                    ?: 'provider_rejected',
            ])->save();

            throw new PhoneVerificationException(
                'otp_delivery_unavailable',
                'The verification code could not be sent. Please try again later.',
                503,
                [
                    'retry_after_seconds' => self::RESEND_COOLDOWN_SECONDS,
                ],
            );
        }

        $verification->forceFill([
            'status' => PhoneVerification::STATUS_PENDING,
            'provider' => $delivery->provider,
            'provider_message_id' => $delivery->messageId,
            'sent_at' => now(),
            'failure_code' => null,
        ])->save();

        return [
            'http_status' => 201,
            ...$this->challengePayload(
                $verification->fresh(),
                $phone,
                false,
                true,
            ),
        ];
    }

    public function verify(
        int $verificationId,
        string $code,
        PhoneVerificationActor $actor,
        string $locale = 'ar',
    ): array {
        $outcome = DB::transaction(function () use (
            $verificationId,
            $code,
            $actor,
            $locale,
        ): array {
            $verification = PhoneVerification::query()
                ->lockForUpdate()
                ->find($verificationId);

            if ($verification === null) {
                throw new PhoneVerificationException(
                    'challenge_not_found',
                    'The verification challenge was not found.',
                    404,
                );
            }

            $this->assertActorMatches($verification, $actor);

            if (
                $verification->status === PhoneVerification::STATUS_VERIFIED
                && $this->verificationIsReusable($verification)
            ) {
                $notification = $this->persistWelcome(
                    $verification,
                    $actor,
                    $locale,
                );

                return [
                    'kind' => 'verified',
                    'verification' => $verification,
                    'notification_id' => $notification->id,
                ];
            }

            if (
                $verification->locked_until !== null
                && now()->lessThan($verification->locked_until)
            ) {
                return [
                    'kind' => 'locked',
                    'locked_until' => $verification->locked_until,
                ];
            }

            if (
                ! in_array($verification->status, [
                    PhoneVerification::STATUS_PENDING,
                    PhoneVerification::STATUS_DISPATCHING,
                ], true)
                || now()->greaterThanOrEqualTo($verification->expires_at)
            ) {
                if (
                    in_array($verification->status, [
                        PhoneVerification::STATUS_PENDING,
                        PhoneVerification::STATUS_DISPATCHING,
                    ], true)
                ) {
                    $verification->forceFill([
                        'status' => PhoneVerification::STATUS_EXPIRED,
                    ])->save();
                }

                return ['kind' => 'expired'];
            }

            if (! Hash::check($code, $verification->code_hash)) {
                $attempts = min(
                    self::MAX_ATTEMPTS,
                    (int) $verification->attempts + 1,
                );
                $lockedUntil = $attempts >= self::MAX_ATTEMPTS
                    ? now()->addSeconds(self::LOCKOUT_SECONDS)
                    : null;

                $verification->forceFill([
                    'attempts' => $attempts,
                    'last_attempt_at' => now(),
                    'locked_until' => $lockedUntil,
                    'status' => $lockedUntil !== null
                        ? PhoneVerification::STATUS_LOCKED
                        : PhoneVerification::STATUS_PENDING,
                ])->save();

                return [
                    'kind' => $lockedUntil !== null ? 'locked' : 'wrong',
                    'attempts_remaining' => max(
                        0,
                        self::MAX_ATTEMPTS - $attempts,
                    ),
                    'locked_until' => $lockedUntil,
                ];
            }

            $verifiedAt = now();
            $verification->forceFill([
                'status' => PhoneVerification::STATUS_VERIFIED,
                'verified_at' => $verifiedAt,
                'verification_expires_at' => $verifiedAt
                    ->copy()
                    ->addDays(self::VERIFIED_DAYS),
                'last_attempt_at' => $verifiedAt,
                'locked_until' => null,
                'failure_code' => null,
            ])->save();

            $this->revokeChangedPhones($verification, $actor);
            $notification = $this->persistWelcome(
                $verification,
                $actor,
                $locale,
            );

            return [
                'kind' => 'verified',
                'verification' => $verification->fresh(),
                'notification_id' => $notification->id,
            ];
        }, 3);

        if ($outcome['kind'] === 'wrong') {
            throw new PhoneVerificationException(
                'incorrect_code',
                'The verification code is incorrect.',
                422,
                [
                    'attempts_remaining' => $outcome['attempts_remaining'],
                ],
            );
        }

        if ($outcome['kind'] === 'locked') {
            throw new PhoneVerificationException(
                'verification_locked',
                'Verification is temporarily locked after too many attempts.',
                429,
                [
                    'attempts_remaining' => 0,
                    'locked_until' => $outcome['locked_until']?->toISOString(),
                    'retry_after_seconds' => $this->secondsUntil(
                        $outcome['locked_until'],
                    ),
                ],
            );
        }

        if ($outcome['kind'] === 'expired') {
            throw new PhoneVerificationException(
                'challenge_expired',
                'The verification code has expired. Request a new code.',
                422,
            );
        }

        return [
            ...$this->verifiedPayload($outcome['verification'], $actor),
            'welcome_notification_id' => $outcome['notification_id'],
            'welcome' => $this->welcomePayload(),
        ];
    }

    public function resolveCheckoutCredential(
        string $verificationToken,
        PhoneVerificationActor $actor,
    ): PhoneVerification {
        try {
            $token = Crypt::decrypt($verificationToken);
        } catch (Throwable) {
            throw new PhoneVerificationException(
                'verification_token_invalid',
                'Phone verification is invalid.',
                422,
            );
        }

        if (! is_array($token) || ($token['version'] ?? null) !== 2) {
            throw new PhoneVerificationException(
                'verification_token_invalid',
                'Phone verification is invalid.',
                422,
            );
        }

        $verification = PhoneVerification::query()
            ->whereKey((int) ($token['verification_id'] ?? 0))
            ->where('status', PhoneVerification::STATUS_VERIFIED)
            ->whereNull('revoked_at')
            ->first();

        if (
            $verification === null
            || ! $this->verificationIsReusable($verification)
            || (int) ($token['expires_at'] ?? 0) <= now()->timestamp
            || ! hash_equals(
                (string) $verification->phone,
                (string) ($token['phone'] ?? ''),
            )
            || ($token['purpose'] ?? null) !== 'checkout'
        ) {
            throw new PhoneVerificationException(
                'verification_token_expired',
                'Phone verification has expired.',
                422,
            );
        }

        if (
            (string) ($token['guest_session_id'] ?? '') !== (string) $actor->guestId()
            || (string) ($token['device_hash'] ?? '') !== (string) $actor->deviceHash
            || (string) ($token['user_id'] ?? '') !== (string) ($actor->userId() ?? '')
        ) {
            throw new PhoneVerificationException(
                'verification_token_actor_mismatch',
                'Phone verification does not belong to this session.',
                403,
            );
        }

        if (
            $verification->user_id !== null
            && (int) $verification->user_id !== (int) $actor->userId()
        ) {
            throw new PhoneVerificationException(
                'verification_token_user_mismatch',
                'Phone verification does not belong to this account.',
                403,
            );
        }

        return $verification;
    }

    public function revoke(
        PhoneVerificationActor $actor,
        ?string $phone = null,
    ): int {
        $query = PhoneVerification::query()
            ->where('status', PhoneVerification::STATUS_VERIFIED)
            ->whereNull('revoked_at');

        if ($actor->userId() !== null) {
            $query->where('user_id', $actor->userId());
        } else {
            $query->where('guest_session_id', $actor->guestId());
        }

        if (filled($phone)) {
            $query->where('phone', $this->normalizePhone((string) $phone));
        }

        return $query->update([
            'status' => PhoneVerification::STATUS_REVOKED,
            'revoked_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function findReusableVerification(
        string $phone,
        string $purpose,
        PhoneVerificationActor $actor,
    ): ?PhoneVerification {
        $base = fn (): Builder => PhoneVerification::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->where('status', PhoneVerification::STATUS_VERIFIED)
            ->whereNull('revoked_at')
            ->whereNotNull('verified_at')
            ->where('verification_expires_at', '>', now());

        if ($actor->userId() !== null) {
            $verification = $base()
                ->where('user_id', $actor->userId())
                ->latest('verified_at')
                ->first();

            if ($verification !== null) {
                return $verification;
            }
        }

        if ($actor->guestId() === null) {
            return null;
        }

        $verification = $base()
            ->where('guest_session_id', $actor->guestId())
            ->where(function (Builder $query) use ($actor): void {
                $query->whereNull('user_id');

                if ($actor->userId() !== null) {
                    $query->orWhere('user_id', $actor->userId());
                }
            })
            ->where(function (Builder $query) use ($actor): void {
                $query->whereNull('device_hash');

                if ($actor->deviceHash !== null) {
                    $query->orWhere('device_hash', $actor->deviceHash);
                }
            })
            ->latest('verified_at')
            ->first();

        if (
            $verification !== null
            && $verification->user_id === null
            && $actor->userId() !== null
        ) {
            $verification->forceFill([
                'user_id' => $actor->userId(),
            ])->save();
        }

        return $verification;
    }

    private function pendingChallengeQuery(
        string $phone,
        string $purpose,
        PhoneVerificationActor $actor,
    ): Builder {
        $query = PhoneVerification::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereIn('status', [
                PhoneVerification::STATUS_DISPATCHING,
                PhoneVerification::STATUS_PENDING,
            ])
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());

        return $this->scopeExactActor($query, $actor);
    }

    private function assertNotLocked(
        string $phone,
        string $purpose,
        PhoneVerificationActor $actor,
    ): void {
        $locked = $this->scopeExactActor(
            PhoneVerification::query()
                ->where('phone', $phone)
                ->where('purpose', $purpose)
                ->where('locked_until', '>', now()),
            $actor,
        )
            ->latest('locked_until')
            ->first();

        if ($locked === null) {
            return;
        }

        throw new PhoneVerificationException(
            'verification_locked',
            'Verification is temporarily locked after too many attempts.',
            429,
            [
                'locked_until' => $locked->locked_until->toISOString(),
                'retry_after_seconds' => $this->secondsUntil(
                    $locked->locked_until,
                ),
            ],
        );
    }

    private function assertActorMatches(
        PhoneVerification $verification,
        PhoneVerificationActor $actor,
    ): void {
        if (
            $verification->guest_session_id !== null
            && ! hash_equals(
                (string) $verification->guest_session_id,
                (string) $actor->guestId(),
            )
        ) {
            throw new PhoneVerificationException(
                'challenge_actor_mismatch',
                'The verification challenge does not belong to this session.',
                403,
            );
        }

        if (
            $verification->user_id !== null
            && (int) $verification->user_id !== (int) $actor->userId()
        ) {
            throw new PhoneVerificationException(
                'challenge_actor_mismatch',
                'The verification challenge does not belong to this account.',
                403,
            );
        }

        if (
            $verification->device_hash !== null
            && ! hash_equals(
                (string) $verification->device_hash,
                (string) $actor->deviceHash,
            )
        ) {
            throw new PhoneVerificationException(
                'challenge_device_mismatch',
                'The verification challenge does not belong to this device.',
                403,
            );
        }
    }

    private function scopeExactActor(
        Builder $query,
        PhoneVerificationActor $actor,
    ): Builder {
        $actor->userId() === null
            ? $query->whereNull('user_id')
            : $query->where('user_id', $actor->userId());
        $actor->guestId() === null
            ? $query->whereNull('guest_session_id')
            : $query->where('guest_session_id', $actor->guestId());

        return $query;
    }

    private function verificationIsReusable(
        PhoneVerification $verification,
    ): bool {
        return $verification->revoked_at === null
            && $verification->verified_at !== null
            && $verification->verification_expires_at !== null
            && now()->lessThan($verification->verification_expires_at);
    }

    private function verifiedPayload(
        PhoneVerification $verification,
        PhoneVerificationActor $actor,
    ): array {
        $tokenExpiresAt = now()
            ->addMinutes(self::CHECKOUT_TOKEN_MINUTES)
            ->min($verification->verification_expires_at);

        $token = Crypt::encrypt([
            'version' => 2,
            'verification_id' => $verification->id,
            'phone' => $verification->phone,
            'purpose' => $verification->purpose,
            'user_id' => $actor->userId(),
            'guest_session_id' => $actor->guestId(),
            'device_hash' => $actor->deviceHash,
            'expires_at' => $tokenExpiresAt->timestamp,
            'nonce' => Str::random(32),
        ]);

        return [
            'verified' => true,
            'requires_otp' => false,
            'phone' => $verification->phone,
            'verified_at' => $verification->verified_at?->toISOString(),
            'verified_until' => $verification
                ->verification_expires_at
                ?->toISOString(),
            'verification_expires_in_seconds' => $this->secondsUntil(
                $verification->verification_expires_at,
            ),
            'verification_token' => $token,
            'verification_token_expires_at' => $tokenExpiresAt->toISOString(),
            'verification_token_expires_in_seconds' => $this->secondsUntil(
                $tokenExpiresAt,
            ),
        ];
    }

    private function unverifiedPayload(
        string $phone,
        string $purpose,
        PhoneVerificationActor $actor,
    ): array {
        $locked = $this->scopeExactActor(
            PhoneVerification::query()
                ->where('phone', $phone)
                ->where('purpose', $purpose)
                ->where('locked_until', '>', now()),
            $actor,
        )
            ->latest('locked_until')
            ->first();
        $remaining = max(
            0,
            self::MAX_DAILY_SENDS - $this->dailySends($phone),
        );

        return [
            'verified' => false,
            'requires_otp' => true,
            'can_send' => $locked === null && $remaining > 0,
            'daily_sends_remaining' => $remaining,
            'locked_until' => $locked?->locked_until?->toISOString(),
        ];
    }

    private function challengePayload(
        PhoneVerification $verification,
        string $phone,
        bool $reused,
        bool $sentByThisRequest,
    ): array {
        $channel = $verification->provider === 'meta_whatsapp'
            ? 'whatsapp'
            : 'internal_test';
        $remaining = max(
            0,
            self::MAX_DAILY_SENDS - $this->dailySends($phone),
        );
        $challenge = [
            'verification_id' => $verification->id,
            'expires_at' => $verification->expires_at?->toISOString(),
            'expires_in_seconds' => $this->secondsUntil(
                $verification->expires_at,
            ),
            'resend_available_at' => $verification
                ->resend_available_at
                ?->toISOString(),
            'resend_after_seconds' => $this->secondsUntil(
                $verification->resend_available_at,
            ),
            'attempts_remaining' => max(
                0,
                self::MAX_ATTEMPTS - (int) $verification->attempts,
            ),
            'code_length' => self::CODE_LENGTH,
            'channel' => $channel,
        ];

        return [
            'verified' => false,
            'requires_otp' => true,
            'sent' => $sentByThisRequest,
            'reused' => $reused,
            'challenge' => $challenge,
            // Compatibility fields contain timing/identity metadata only.
            'verification_id' => $challenge['verification_id'],
            'expires_in_seconds' => $challenge['expires_in_seconds'],
            'channel' => $channel,
            'can_send' => $challenge['resend_after_seconds'] === 0
                && $remaining > 0,
            'daily_sends_remaining' => $remaining,
            'locked_until' => $verification->locked_until?->toISOString(),
        ];
    }

    private function revokeChangedPhones(
        PhoneVerification $current,
        PhoneVerificationActor $actor,
    ): void {
        $revoke = static function (Builder $query) use ($current): void {
            $query
                ->whereKeyNot($current->id)
                ->where('phone', '!=', $current->phone)
                ->where('status', PhoneVerification::STATUS_VERIFIED)
                ->whereNull('revoked_at')
                ->update([
                    'status' => PhoneVerification::STATUS_REVOKED,
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);
        };

        if ($actor->userId() !== null) {
            $revoke(
                PhoneVerification::query()
                    ->where('user_id', $actor->userId()),
            );
        }

        if ($actor->guestId() !== null) {
            $revoke(
                PhoneVerification::query()
                    ->where('guest_session_id', $actor->guestId()),
            );
        }
    }

    private function persistWelcome(
        PhoneVerification $verification,
        PhoneVerificationActor $actor,
        string $locale,
    ): mixed {
        return $this->notifications->persistPhoneVerifiedWelcome(
            $actor->userId(),
            $actor->userId() === null ? $actor->guestId() : null,
            'phone-verification:'.$verification->id.':welcome',
            $locale,
        );
    }

    private function welcomePayload(): array
    {
        return [
            'title_ar' => '🎉 أهلًا بك في زاد سينك!',
            'message_ar' => "🎉 أهلًا بك في زاد سينك!\n✅ تم توثيق رقم جوالك بنجاح لمدة 10 أيام.\n🧡 تسوّق واطلب من الأسر المنتجة بكل سهولة،\nدون الحاجة لإعادة رمز التحقق خلال هذه المدة.\nزاد سينك — إبداع الأسر بين يديك.",
            'title_en' => '🎉 Welcome to ZAD Sync!',
            'message_en' => "🎉 Welcome to ZAD Sync!\n✅ Your mobile number has been verified for 10 days.\n🧡 Shop and order from productive families with ease,\nwithout entering another verification code during this period.\nZAD Sync — productive families' creativity at your fingertips.",
        ];
    }

    private function lockDailyCounter(string $phone): void
    {
        $now = now();

        DB::table('phone_verification_send_counters')->insertOrIgnore([
            'phone_hash' => $this->phoneHash($phone),
            'send_date' => $this->riyadhDate(),
            'sends' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('phone_verification_send_counters')
            ->where('phone_hash', $this->phoneHash($phone))
            ->where('send_date', $this->riyadhDate())
            ->lockForUpdate()
            ->first();
    }

    private function dailySends(string $phone): int
    {
        return (int) DB::table('phone_verification_send_counters')
            ->where('phone_hash', $this->phoneHash($phone))
            ->where('send_date', $this->riyadhDate())
            ->value('sends');
    }

    private function riyadhDate(): string
    {
        return now('Asia/Riyadh')->toDateString();
    }

    private function secondsUntilNextRiyadhDay(): int
    {
        $now = now('Asia/Riyadh');

        return max(1, $now->diffInSeconds($now->copy()->addDay()->startOfDay()));
    }

    private function secondsUntil(mixed $date): int
    {
        if ($date === null) {
            return 0;
        }

        return max(0, (int) $date->timestamp - now()->timestamp);
    }

    private function normalizePhone(string $phone): string
    {
        $normalized = InternalTesting::normalizePhone($phone);

        if (! preg_match('/^\+9665[0-9]{8}$/', $normalized)) {
            throw new PhoneVerificationException(
                'invalid_phone',
                'A valid Saudi mobile number is required.',
                422,
            );
        }

        return $normalized;
    }

    private function phoneHash(string $phone): string
    {
        return $this->secureHash('phone|'.$phone);
    }

    private function secureHash(string $value): string
    {
        return hash_hmac(
            'sha256',
            $value,
            (string) config('app.key'),
        );
    }
}
