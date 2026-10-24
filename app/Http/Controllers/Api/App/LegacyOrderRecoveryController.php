<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppGuestSession;
use App\Models\Order;
use App\Models\PhoneVerification;
use App\Services\App\GuestSessionAuthenticator;
use App\Services\App\LegacyOrderRecoveryPolicy;
use App\Services\PhoneVerification\TawkedSmsOtpProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Feature-gated, non-mutating access recovery for completed legacy guest orders only. */
final class LegacyOrderRecoveryController extends Controller
{
    private const PURPOSE = 'legacy_order_recovery';

    public function __construct(
        private readonly GuestSessionAuthenticator $auth,
        private readonly LegacyOrderRecoveryPolicy $policy,
        private readonly TawkedSmsOtpProvider $sms,
    ) {}

    public function start(Request $request, Order $order): JsonResponse
    {
        $this->enabled();
        $data = $request->validate([
            'legacy_guest_session_id' => ['required', 'uuid'],
            'phone' => ['required', 'string', 'regex:/^05[0-9]{8}$/D'],
            'device_id' => ['required', 'string', 'max:191'],
            'request_id' => ['required', 'uuid'],
        ]);
        [$current, $legacy] = $this->authorizeRecovery($request, $order, $data);
        $this->enabledSms();
        $key = hash('sha256', 'legacy|'.$order->id.'|'.$current->id.'|'.$data['phone'].'|'.strtolower($data['request_id']));
        $challenge = DB::transaction(function () use ($order, $current, $data, $key, $request): array {
            $old = Order::query()->lockForUpdate()->findOrFail($order->id);
            $legacy = AppGuestSession::query()->findOrFail($data['legacy_guest_session_id']);
            abort_unless($this->policy->eligible($old, $legacy, $current, $data['phone'], $data['device_id']), 404);
            $existing = PhoneVerification::query()->where('send_idempotency_key', $key)->first();
            if ($existing !== null) return ['row' => $existing, 'send' => false];

            $last = PhoneVerification::query()->where('order_id', $old->id)
                ->where('purpose', self::PURPOSE)->latest('id')->first();
            abort_if($last?->resend_available_at?->isFuture() || $last?->locked_until?->isFuture(), 429);

            $phoneHash = hash_hmac('sha256', $data['phone'], (string) config('app.key'));
            DB::table('phone_verification_send_counters')->insertOrIgnore([
                'phone_hash' => $phoneHash, 'send_date' => now()->toDateString(),
                'sends' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $counter = DB::table('phone_verification_send_counters')
                ->where('phone_hash', $phoneHash)->where('send_date', now()->toDateString())
                ->lockForUpdate()->first();
            abort_if($counter === null || (int) $counter->sends >= (int) config('legacy_order_recovery.max_daily_sends', 3), 429);
            PhoneVerification::query()->where('order_id', $old->id)->where('purpose', self::PURPOSE)
                ->whereIn('status', [PhoneVerification::STATUS_PENDING, PhoneVerification::STATUS_DISPATCHING])
                ->update(['status' => PhoneVerification::STATUS_REVOKED, 'revoked_at' => now()]);
            $row = PhoneVerification::query()->create([
                'order_id' => $old->id, 'guest_session_id' => $current->id,
                'phone' => $data['phone'], 'purpose' => self::PURPOSE,
                'status' => PhoneVerification::STATUS_DISPATCHING, 'attempts' => 0,
                'code_hash' => Hash::make(Str::random(64)), 'code_length' => 4,
                'expires_at' => now()->addMinutes(5),
                'resend_available_at' => now()->addSeconds((int) config('legacy_order_recovery.resend_cooldown_seconds', 120)),
                'send_idempotency_key' => $key,
                'device_hash' => hash('sha256', $data['device_id']),
                'request_ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            ]);
            DB::table('phone_verification_send_counters')->where('id', $counter->id)
                ->increment('sends', 1, ['updated_at' => now()]);
            return ['row' => $row, 'send' => true];
        }, 3);

        $row = $challenge['row'];
        if (! $challenge['send']) {
            abort_unless($row->status === PhoneVerification::STATUS_PENDING && $row->sent_at
                && (string) $row->guest_session_id === (string) $current->id, 409);
            return response()->json(['verification_id' => $row->id, 'expires_in_seconds' => max(0, now()->diffInSeconds($row->expires_at))]);
        }

        // No network operation is performed inside the DB transaction.
        $result = $this->sms->startVerification($data['phone'], $key);
        $row->refresh();
        abort_unless($row->status === PhoneVerification::STATUS_DISPATCHING, 409);
        if (! $result->accepted) {
            $row->update(['status' => PhoneVerification::STATUS_FAILED,
                'provider' => $result->provider, 'failure_code' => $result->failureCode]);
            abort(503, 'تعذر إرسال رمز الاستعادة. يمكنك التواصل مع الدعم.');
        }
        $row->update(['status' => PhoneVerification::STATUS_PENDING, 'sent_at' => now(),
            'provider' => $result->provider, 'provider_message_id' => $result->messageId]);
        return response()->json(['verification_id' => $row->id, 'expires_in_seconds' => 300], 201);
    }

    public function check(Request $request, Order $order): JsonResponse
    {
        $this->enabled();
        $data = $request->validate([
            'legacy_guest_session_id' => ['required', 'uuid'],
            'phone' => ['required', 'string', 'regex:/^05[0-9]{8}$/D'],
            'device_id' => ['required', 'string', 'max:191'],
            'verification_id' => ['required', 'integer'],
            'code' => ['required', 'digits:4'],
        ]);
        [$current, $legacy] = $this->authorizeRecovery($request, $order, $data);
        $this->enabledSms();
        $row = PhoneVerification::query()->whereKey($data['verification_id'])
            ->where('order_id', $order->id)->where('guest_session_id', $current->id)
            ->where('phone', $data['phone'])->where('purpose', self::PURPOSE)->firstOrFail();
        abort_unless(hash_equals((string) $row->device_hash, hash('sha256', $data['device_id']))
            && $row->status === PhoneVerification::STATUS_PENDING
            && $row->expires_at?->isFuture() && $row->provider_message_id, 403);

        DB::transaction(function () use ($row): void {
            $locked = PhoneVerification::query()->lockForUpdate()->findOrFail($row->id);
            abort_unless($locked->status === PhoneVerification::STATUS_PENDING
                && $locked->expires_at?->isFuture() && (int) $locked->attempts < 3, 429);
            $locked->update(['attempts' => (int) $locked->attempts + 1, 'last_attempt_at' => now()]);
        }, 3);
        $result = $this->sms->checkVerification((string) $row->provider_message_id, $data['code']);
        abort_unless($result['ok'], 503, 'تعذّر التحقق. حاول لاحقًا.');
        if (! $result['verified']) {
            $row->refresh();
            $locked = $row->attempts >= 3 || in_array($result['status'], ['expired', 'canceled', 'too_many_attempts'], true);
            if ($locked && $row->status === PhoneVerification::STATUS_PENDING) {
                $row->update(['status' => PhoneVerification::STATUS_LOCKED, 'locked_until' => now()->addMinutes(15)]);
            }
            return response()->json(['message' => 'الرمز غير صحيح أو انتهت صلاحيته.'], 422);
        }

        DB::transaction(function () use ($order, $current, $legacy, $row, $data): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($this->policy->eligible($lockedOrder, $legacy->fresh(), $current->fresh(),
                $data['phone'], $data['device_id']), 409);
            $lockedOtp = PhoneVerification::query()->lockForUpdate()->findOrFail($row->id);
            abort_unless($lockedOtp->status === PhoneVerification::STATUS_PENDING
                && $lockedOtp->expires_at?->isFuture() && $lockedOtp->provider_message_id, 409);
            DB::table('legacy_order_access_grants')->insertOrIgnore([
                'order_id' => $lockedOrder->id, 'guest_session_id' => $current->id,
                'legacy_guest_session_id' => $legacy->id,
                'phone_hash' => hash_hmac('sha256', $data['phone'], (string) config('app.key')),
                'verified_at' => now(), 'expires_at' => now()->addDays(10),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $lockedOtp->update(['status' => PhoneVerification::STATUS_VERIFIED,
                'verified_at' => now(), 'verification_expires_at' => now()->addDays(10)]);
        }, 3);
        return response()->json(['recovered' => true, 'order_id' => $order->id]);
    }

    private function authorizeRecovery(Request $request, Order $order, array $data): array
    {
        $id = trim((string) $request->header('X-Guest-Session', ''));
        abort_unless($id !== '', 401);
        $current = $this->auth->authenticate($id, $this->auth->tokenFromRequest($request), $data['device_id']);
        $legacy = AppGuestSession::query()->find($data['legacy_guest_session_id']);
        abort_unless($legacy && $this->policy->eligible($order, $legacy, $current, $data['phone'], $data['device_id']), 404);
        return [$current, $legacy];
    }

    private function enabled(): void
    {
        abort_unless(config('legacy_order_recovery.enabled', false), 404);
    }

    private function enabledSms(): void
    {
        abort_unless(config('legacy_order_recovery.allow_live_sms', false)
            && (string) config('zad_tawked.expected_mode') === 'live'
            && $this->sms->ready(), 503, 'استعادة الطلبات غير مفعلة حاليًا، تواصل مع الدعم.');
    }
}
