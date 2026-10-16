<?php

namespace App\Http\Controllers\Api\App;

use App\Contracts\OtpDeliveryProvider;
use App\Http\Controllers\Controller;
use App\Models\AppGuestSession;
use App\Models\PhoneVerification;
use App\Services\App\GuestSessionAuthenticator;
use App\Services\Notifications\TransactionalNotificationService;
use App\Support\InternalTesting;
use App\Services\PhoneVerification\StagingTestOtpGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Checkout-only OTP API. Fail closed: never return an OTP or silently send SMS.
 * Meta/Firebase credentials are configured separately; this class does not
 * enable a live provider or bypass the approved authentication template gate.
 */
class PhoneVerificationController extends Controller
{
    public function __construct(
        private readonly GuestSessionAuthenticator $guests,
        private readonly OtpDeliveryProvider $delivery,
        private readonly TransactionalNotificationService $notifications,
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        $data = $this->identity($request);
        $guest = $this->authenticate($request, $data);
        $phone = InternalTesting::normalizePhone($data['phone']);

        if ($qaVerification = $this->stagingQaAutoVerification($request, $guest, $phone)) {
            return response()->json($this->verifiedState($qaVerification, $phone));
        }

        return response()->json($this->state($guest, $phone, false));
    }

    /**
     * Temporary checkout QA only. This is NOT proof of phone ownership.
     * Never works against Production or another database, even if enabled.
     */
    private function stagingQaAutoVerification(Request $request, AppGuestSession $guest, string $phone): ?PhoneVerification
    {
        if (! app()->environment('staging')
            || config('phone_verification.provider') !== 'staging_test'
            || ! (bool) config('phone_verification.qa_auto_verify', false)) {
            return null;
        }

        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql'
            || (string) $connection->getConfig('host') !== 'dpg-db783kqj9qps73bdatn0-a'
            || $connection->getDatabaseName() !== 'zad_staging'
            || (DB::selectOne('SELECT current_database() AS name')->name ?? null) !== 'zad_staging') {
            return null;
        }

        $rawPhones = preg_split('/[,;\s]+/', trim((string) config('phone_verification.staging_test_phones', '')));
        $allowedPhones = is_array($rawPhones)
            ? array_map(static fn (string $p): string => InternalTesting::normalizePhone($p),
                array_filter($rawPhones, static fn (string $p): bool => $p !== ''))
            : [];
        if (! in_array($phone, $allowedPhones, true)) {
            return null;
        }

        return DB::transaction(function () use ($request, $guest, $phone): PhoneVerification {
            // The normal guest-session authentication already ran before this helper.
            AppGuestSession::query()->whereKey($guest->id)->lockForUpdate()->firstOrFail();
            if ($existing = $this->activeVerified($guest, $phone)) {
                return $existing;
            }

            $now = now();
            $verification = PhoneVerification::query()->create([
                'phone' => $phone,
                'purpose' => 'checkout',
                'guest_session_id' => $guest->id,
                'status' => PhoneVerification::STATUS_VERIFIED,
                'provider' => 'staging_qa_auto',
                'code_hash' => Hash::make(Str::random(64)),
                'code_length' => 6,
                'attempts' => 0,
                'expires_at' => $now->copy()->addMinutes(5),
                'verified_at' => $now,
                'verification_expires_at' => $now->copy()->addMinutes(30),
                'send_idempotency_key' => hash('sha256', 'staging-qa-auto:'.(string) Str::uuid()),
                'device_hash' => hash('sha256', (string) $request->input('device_id')),
                'request_ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            ]);
            logger()->notice('STAGING QA ONLY: mock phone verification granted', [
                'verification_id' => $verification->id,
                'guest_id' => $guest->id,
            ]);
            return $verification;
        }, 3);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $this->identity($request);
        $request->validate(['request_id' => ['required', 'uuid']]);
        $guest = $this->authenticate($request, $data);
        $phone = InternalTesting::normalizePhone($data['phone']);
        $requestKey = hash('sha256', $phone.'|'.$guest->id.'|'.strtolower((string) $request->input('request_id')));

        // Staging test OTP is strictly isolated and allow-listed.
        if (config('phone_verification.provider') === 'staging_test'
            && ! StagingTestOtpGuard::allowedPhone($phone)) {
            return response()->json([
                'message' => 'رمز الاختبار غير مفعل لهذا الرقم في Staging.',
                'code' => 'staging_test_otp_blocked',
            ], 503);
        }

        // Enabling real sends requires an approved Meta Authentication template.
        // Never fall back to publicly displayed test codes.
        if (config('phone_verification.provider') === 'meta_whatsapp') {
            $meta = (array) config('services.meta_whatsapp', []);
            $ready = (bool) ($meta['authentication_template_approved'] ?? false)
                && (int) ($meta['authentication_template_code_length'] ?? 0) === 6
                && trim((string) ($meta['access_token'] ?? '')) !== ''
                && trim((string) ($meta['phone_number_id'] ?? '')) !== ''
                && trim((string) ($meta['authentication_template'] ?? '')) !== ''
                && trim((string) ($meta['graph_version'] ?? '')) !== '';
            if (! $ready) {
                return response()->json([
                    'message' => 'توثيق الجوال عبر واتساب قيد التفعيل. لن يتم إرسال رمز تجريبي.',
                    'code' => 'meta_template_not_approved',
                ], 503);
            }
        }

        $reservation = DB::transaction(function () use ($request, $phone, $guest, $requestKey): array {
            AppGuestSession::query()->whereKey($guest->id)->lockForUpdate()->firstOrFail();
            $verified = $this->activeVerified($guest, $phone);
            if ($verified) {
                return ['verified' => $verified, 'send' => false];
            }
            $existingRequest = PhoneVerification::query()
                ->where('send_idempotency_key', $requestKey)->lockForUpdate()->first();
            if ($existingRequest) {
                if ($existingRequest->status === PhoneVerification::STATUS_FAILED) {
                    $this->reject('send_uncertain', 'تعذر تأكيد إرسال الرمز السابق. انتظر ثم حاول برمز طلب جديد.', 409);
                }
                return ['challenge' => $existingRequest, 'send' => false];
            }

            $latest = $this->latestChallenge($guest, $phone);
            if ($latest?->locked_until && $latest->locked_until->isFuture()) {
                $this->reject('otp_locked', 'تم إيقاف المحاولات مؤقتًا. حاول لاحقًا.', 429, [
                    'retry_after_seconds' => $this->secondsUntil($latest->locked_until),
                ]);
            }
            if ($latest?->resend_available_at && $latest->resend_available_at->isFuture()) {
                $this->reject('otp_cooldown', 'انتظر قليلًا قبل طلب رمز جديد.', 429, [
                    'retry_after_seconds' => $this->secondsUntil($latest->resend_available_at),
                ]);
            }

            $counterKey = hash_hmac('sha256', $phone, (string) config('app.key'));
            $today = now()->toDateString();
            DB::table('phone_verification_send_counters')->insertOrIgnore([
                'phone_hash' => $counterKey,
                'send_date' => $today,
                'sends' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $counter = DB::table('phone_verification_send_counters')
                ->where('phone_hash', $counterKey)
                ->where('send_date', $today)
                ->lockForUpdate()->first();
            if (! $counter || $counter->sends >= (int) config('phone_verification.max_daily_sends', 4)) {
                $this->reject('daily_limit', 'اكتمل الحد اليومي لرموز التحقق. حاول غدًا.', 429);
            }

            // Invalidate earlier unverified challenges; never delete verified
            // records as those preserve ten-day checkout authorization.
            PhoneVerification::query()
                ->where('guest_session_id', $guest->id)
                ->where('phone', $phone)
                ->where('purpose', 'checkout')
                ->whereNull('verified_at')
                ->where('status', PhoneVerification::STATUS_PENDING)
                ->update(['status' => PhoneVerification::STATUS_FAILED, 'updated_at' => now()]);

            $length = (int) config('phone_verification.code_length', 6);
            if ($length !== 6) {
                $this->reject('unsupported_code_length', 'قالب التوثيق الحالي يتطلب ستة أرقام.', 503);
            }
            $otp = config('phone_verification.provider') === 'staging_test'
                ? StagingTestOtpGuard::codeForRequest($requestKey)
                : (string) random_int(100000, 999999);
            $now = now();
            $verification = PhoneVerification::query()->create([
                'phone' => $phone,
                'purpose' => 'checkout',
                'guest_session_id' => $guest->id,
                'status' => PhoneVerification::STATUS_DISPATCHING,
                'provider' => (string) config('phone_verification.provider', 'meta_whatsapp'),
                'code_hash' => Hash::make($otp),
                'code_length' => $length,
                'attempts' => 0,
                'expires_at' => $now->copy()->addSeconds((int) config('phone_verification.challenge_ttl_seconds', 240)),
                'resend_available_at' => $now->copy()->addSeconds((int) config('phone_verification.resend_cooldown_seconds', 90)),
                'send_idempotency_key' => $requestKey,
                'request_ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
                'device_hash' => hash('sha256', $request->input('device_id')),
            ]);
            DB::table('phone_verification_send_counters')
                ->where('id', $counter->id)->increment('sends', 1, ['updated_at' => now()]);
            return ['challenge' => $verification, 'otp' => $otp, 'send' => true];
        }, 3);

        if (isset($reservation['verified'])) {
            return response()->json($this->verifiedState($reservation['verified'], $phone));
        }
        $verification = $reservation['challenge'];
        if (! $reservation['send']) {
            return response()->json($this->challengeState($verification, false, true));
        }

        // Network timeout is ambiguous: never automatically retry a Meta send.
        $result = $this->delivery->sendAuthenticationCode($phone, $reservation['otp'], $requestKey);
        if (! $result->accepted) {
            $verification->forceFill([
                'status' => PhoneVerification::STATUS_FAILED,
                'provider' => $result->provider,
                'failure_code' => $result->failureCode,
            ])->save();
            return response()->json([
                'message' => 'تعذر تأكيد إرسال رمز واتساب. لم يتم توثيق الرقم.',
                'code' => 'otp_delivery_failed',
            ], 503);
        }

        $verification->forceFill([
            'status' => PhoneVerification::STATUS_PENDING,
            'sent_at' => now(),
            'provider' => $result->provider,
            'provider_message_id' => $result->messageId,
        ])->save();

        return response()->json($this->challengeState($verification, true, false), 201);
    }

    /**
     * Test-operator access only. Never exposed on Production; never enabled
     * without an isolated staging DB, a 32+ character secret, and phone allowlist.
     */
    public function stagingTestCode(Request $request): JsonResponse
    {
        if (! StagingTestOtpGuard::enabled()) {
            abort(404);
        }
        $provided = $request->header('X-ZAD-Staging-Test-Secret');
        $expected = (string) config('phone_verification.staging_test_secret', '');
        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            abort(404);
        }
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^05[0-9]{8}$/'],
        ]);
        $phone = InternalTesting::normalizePhone($data['phone']);
        if (! StagingTestOtpGuard::allowedPhone($phone)) {
            abort(404);
        }
        $verification = PhoneVerification::query()
            ->where('phone', $phone)
            ->where('purpose', 'checkout')
            ->where('provider', 'staging_test')
            ->where('status', PhoneVerification::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
        if ($verification === null || ! is_string($verification->send_idempotency_key)) {
            abort(404);
        }
        $code = StagingTestOtpGuard::codeForRequest($verification->send_idempotency_key);
        if (! Hash::check($code, $verification->code_hash)) {
            abort(404);
        }
        return response()->json([
            'code' => $code,
            'expires_in_seconds' => max(0, $verification->expires_at->timestamp - now()->timestamp),
        ])->header('Cache-Control', 'no-store, private')
          ->header('Pragma', 'no-cache');
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification_id' => ['required', 'integer', 'exists:phone_verifications,id'],
            'code' => ['required', 'digits:6'],
            'purpose' => ['required', 'in:checkout'],
            'guest_session_id' => ['required', 'uuid', 'exists:app_guest_sessions,id'],
            'device_id' => ['required', 'string', 'max:191'],
        ]);
        $guest = $this->authenticate($request, $data);

        $result = DB::transaction(function () use ($guest, $data): array {
            $verification = PhoneVerification::query()->lockForUpdate()->findOrFail($data['verification_id']);
            if ($verification->guest_session_id !== $guest->id || $verification->purpose !== 'checkout') {
                $this->reject('otp_wrong_session', 'رمز التحقق لا يخص هذه الجلسة.');
            }
            if ($verification->status === PhoneVerification::STATUS_VERIFIED
                && $verification->verification_expires_at?->isFuture()
                && $verification->revoked_at === null) {
                return ['verification' => $verification, 'fresh' => false];
            }
            if ($verification->locked_until?->isFuture()) {
                $this->reject('otp_locked', 'تم إيقاف المحاولات مؤقتًا. حاول لاحقًا.', 429, [
                    'retry_after_seconds' => $this->secondsUntil($verification->locked_until),
                ]);
            }
            if ($verification->status !== PhoneVerification::STATUS_PENDING
                || ! $verification->expires_at?->isFuture()) {
                $this->reject('otp_expired', 'انتهت صلاحية الرمز. اطلب رمزًا جديدًا.');
            }
            if (! Hash::check($data['code'], $verification->code_hash)) {
                $attempts = (int) $verification->attempts + 1;
                $locked = $attempts >= (int) config('phone_verification.max_attempts', 3);
                $verification->forceFill([
                    'attempts' => $attempts,
                    'last_attempt_at' => now(),
                    'status' => $locked ? PhoneVerification::STATUS_LOCKED : PhoneVerification::STATUS_PENDING,
                    'locked_until' => $locked ? now()->addSeconds((int) config('phone_verification.lockout_seconds', 900)) : null,
                ])->save();
                // We must commit the increment before signaling an invalid code.
                return ['failure' => true, 'remaining' => max(0, (int) config('phone_verification.max_attempts', 3) - $attempts),
                    'locked_until' => $verification->locked_until];
            }
            $verification->forceFill([
                'status' => PhoneVerification::STATUS_VERIFIED,
                'verified_at' => now(),
                'verification_expires_at' => now()->addDays((int) config('phone_verification.verified_days', 10)),
                'code_hash' => Hash::make(Str::random(64)),
            ])->save();
            // Store the welcome once, keyed to this verification record.
            $notification = $this->notifications->persistPhoneVerifiedWelcome(
                null, $guest->id, 'phone-verification:'.$verification->id,
            );
            return ['verification' => $verification, 'fresh' => true, 'notification_id' => $notification->id];
        }, 3);

        if (isset($result['failure'])) {
            $lockedUntil = $result['locked_until'];
            return response()->json([
                'message' => $lockedUntil ? 'تم استنفاد المحاولات. حاول بعد 15 دقيقة.' : 'الرمز غير صحيح.',
                'code' => $lockedUntil ? 'otp_locked' : 'otp_invalid',
                'attempts_remaining' => $result['remaining'],
                'retry_after_seconds' => $lockedUntil ? $this->secondsUntil($lockedUntil) : null,
            ], $lockedUntil ? 429 : 422);
        }

        $verification = $result['verification'];
        $payload = $this->verifiedState($verification, $verification->phone);
        if ($result['fresh']) {
            $payload['welcome'] = $this->welcomeTemplate();
            $payload['welcome_notification_id'] = $result['notification_id'];
        }
        return response()->json($payload);
    }

    private function identity(Request $request): array
    {
        return $request->validate([
            'phone' => ['required', 'string', 'regex:/^05[0-9]{8}$/'],
            'purpose' => ['required', 'in:checkout'],
            'guest_session_id' => ['required', 'uuid', 'exists:app_guest_sessions,id'],
            'device_id' => ['required', 'string', 'max:191'],
        ]);
    }

    private function authenticate(Request $request, array $data): AppGuestSession
    {
        try {
            return $this->guests->authenticate(
                $data['guest_session_id'],
                $this->guests->tokenFromRequest($request),
                $data['device_id'],
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 401) {
                throw $exception;
            }
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'message' => 'انتهت جلسة الجهاز، يرجى تجديدها والمحاولة مرة أخرى.',
                    'code' => 'guest_session_invalid',
                ], 401),
            );
        }
    }

    private function latestChallenge(AppGuestSession $guest, string $phone): ?PhoneVerification
    {
        return PhoneVerification::query()->where('guest_session_id', $guest->id)
            ->where('phone', $phone)->where('purpose', 'checkout')
            ->latest('id')->first();
    }

    private function activeVerified(AppGuestSession $guest, string $phone): ?PhoneVerification
    {
        return PhoneVerification::query()->where('guest_session_id', $guest->id)
            ->where('phone', $phone)->where('purpose', 'checkout')
            ->where('status', PhoneVerification::STATUS_VERIFIED)
            ->whereNotNull('verified_at')->whereNull('revoked_at')
            ->where('verification_expires_at', '>', now())
            ->latest('id')->first();
    }

    private function state(AppGuestSession $guest, string $phone, bool $sent): array
    {
        if ($verification = $this->activeVerified($guest, $phone)) {
            return $this->verifiedState($verification, $phone);
        }
        $latest = $this->latestChallenge($guest, $phone);
        $locked = $latest?->locked_until?->isFuture() === true;
        $cooldown = $latest?->resend_available_at?->isFuture() === true;
        $counterKey = hash_hmac('sha256', $phone, (string) config('app.key'));
        $usedToday = (int) DB::table('phone_verification_send_counters')
            ->where('phone_hash', $counterKey)
            ->where('send_date', now()->toDateString())
            ->value('sends');
        $remaining = max(0, (int) config('phone_verification.max_daily_sends', 4) - $usedToday);
        $pending = $latest && in_array($latest->status, [
            PhoneVerification::STATUS_PENDING,
            PhoneVerification::STATUS_DISPATCHING,
        ], true) && $latest->expires_at?->isFuture();
        $challenge = $pending ? $this->challengeState($latest, $sent, true) : [];
        return [
            ...$challenge,
            'verified' => false,
            'requires_otp' => true,
            'can_send' => ! $locked && ! $cooldown && $remaining > 0,
            'daily_sends_remaining' => $remaining,
            'locked_until' => $locked ? $latest->locked_until->toISOString() : null,
        ];
    }

    private function challengeState(PhoneVerification $verification, bool $sent, bool $reused): array
    {
        return [
            'verified' => false,
            'requires_otp' => true,
            'sent' => $sent,
            'reused' => $reused,
            'can_send' => ! $verification->resend_available_at?->isFuture(),
            'challenge' => [
                'verification_id' => $verification->id,
                'code_length' => (int) $verification->code_length,
                'channel' => $verification->provider === 'staging_test' ? 'staging_test' : 'whatsapp',
                'expires_in_seconds' => $this->secondsUntil($verification->expires_at),
                'resend_after_seconds' => $this->secondsUntil($verification->resend_available_at),
                'attempts_remaining' => max(0, (int) config('phone_verification.max_attempts', 3) - (int) $verification->attempts),
            ],
        ];
    }

    private function verifiedState(PhoneVerification $verification, string $phone): array
    {
        $guest = AppGuestSession::query()->findOrFail($verification->guest_session_id);
        if ($verification->verification_expires_at &&
            ($guest->session_token_expires_at === null ||
             $guest->session_token_expires_at->lessThan($verification->verification_expires_at))) {
            $guest->forceFill([
                'session_token_expires_at' => $verification->verification_expires_at,
            ])->save();
        }
        return [
            'guest_session_token_expires_at' => $guest->session_token_expires_at?->toISOString(),
            'verified' => true,
            'requires_otp' => false,
            'can_send' => false,
            'phone' => $phone,
            'verified_until' => $verification->verification_expires_at?->toISOString(),
            'verification_token' => encrypt([
                'id' => $verification->id,
                'phone' => $phone,
                'purpose' => 'checkout',
                'guest_session_id' => $verification->guest_session_id,
                'expires' => min(
                    now()->addMinutes((int) config('phone_verification.checkout_token_minutes', 20))->timestamp,
                    $verification->verification_expires_at->timestamp,
                ),
            ]),
        ];
    }

    private function welcomeTemplate(): array
    {
        $ar = \App\Models\NotificationTemplate::query()->where('key', 'phone_verified_welcome')->where('locale', 'ar')->first();
        $en = \App\Models\NotificationTemplate::query()->where('key', 'phone_verified_welcome')->where('locale', 'en')->first();
        return [
            'title_ar' => $ar?->title ?? '🎉 أهلًا بك في زاد سينك!',
            'message_ar' => $ar?->body ?? '✅ تم توثيق رقم جوالك لمدة 10 أيام.',
            'title_en' => $en?->title ?? '🎉 Welcome to ZAD Sync!',
            'message_en' => $en?->body ?? '✅ Your mobile number has been verified for 10 days.',
        ];
    }

    private function secondsUntil(?\DateTimeInterface $date): int
    {
        return $date ? max(0, (int) ceil(now()->diffInSeconds($date, false))) : 0;
    }

    private function reject(string $code, string $message, int $status = 422, array $extras = []): never
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json([
                'message' => $message,
                'code' => $code,
                ...$extras,
            ], $status),
        );
    }
}
