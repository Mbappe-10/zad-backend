<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PhoneVerification;
use App\Services\App\GuestSessionAuthenticator;
use App\Services\Notifications\TransactionalNotificationService;
use App\Services\PhoneVerification\TawkedSmsOtpProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Review candidate only. No SMS without the real LIVE Moyasar proof and separate SMS flag. */
final class OrderContactVerificationController extends Controller
{
    private const PURPOSE = 'postpay_contact';

    public function __construct(
        private readonly GuestSessionAuthenticator $guests,
        private readonly TawkedSmsOtpProvider $tawked,
        private readonly TransactionalNotificationService $notifications,
    ) {}

    public function status(Request $request, Order $order): JsonResponse
    {
        $this->enabled();
        $data = $request->validate(['device_id' => ['required','string','max:191']]);
        $this->owns($request, $order, $data['device_id']);
        return response()->json(['data' => $this->state($order->fresh())]);
    }

    public function changePhone(Request $request, Order $order): JsonResponse
    {
        $this->enabled();
        $data = $request->validate([
            'device_id' => ['required','string','max:191'],
            'phone' => ['required','string','regex:/^05[0-9]{8}$/D'],
        ]);
        $this->owns($request, $order, $data['device_id']);
        $this->assertEligiblePaid($order);
        DB::transaction(function () use ($order, $data): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->held($locked);
            $this->assertEligiblePaid($locked);
            $this->assertAllowedOtpDestination($locked, $data['phone']);
            if (($locked->pending_contact_phone ?: $locked->contact_phone) === $data['phone']) return;
            abort_if((int) $locked->contact_phone_edit_count >= (int) config('postpay_otp.max_phone_edits', 3),
                429, 'بلغت الحد المسموح لتعديل الرقم. تواصل مع الدعم.');
            $locked->update(['pending_contact_phone' => $data['phone'],
                'contact_phone_edit_count' => (int) $locked->contact_phone_edit_count + 1]);
            $this->revokePending($locked);
        }, 3);
        return response()->json(['data' => $this->state($order->fresh())]);
    }

    public function start(Request $request, Order $order): JsonResponse
    {
        $this->enabled();
        $data = $request->validate([
            'device_id' => ['required','string','max:191'],
            'request_id' => ['required','uuid'],
        ]);
        $this->owns($request, $order, $data['device_id']);
        $this->assertEligiblePaid($order);
        $this->held($order);
        $phone = (string) ($order->pending_contact_phone ?: $order->contact_phone);
        abort_unless(preg_match('/^05[0-9]{8}$/D', $phone), 422);
        $this->assertAllowedOtpDestination($order, $phone);
        // A verified phone may be reused only for the very same authenticated guest.
        $prior = PhoneVerification::query()->where('guest_session_id', $order->guest_session_id)
            ->where('phone', $phone)->whereIn('purpose', [self::PURPOSE, 'checkout'])
            ->where('status', PhoneVerification::STATUS_VERIFIED)->whereNull('revoked_at')
            ->where('verification_expires_at', '>', now())->latest('id')->first();
        if ($prior) {
            $this->release($order, $phone);
            return response()->json(['data' => $this->state($order->fresh())]);
        }
        abort_unless(config('postpay_otp.allow_live_sms', false) && $this->tawked->ready(),
            503, 'إرسال الرسائل غير مفعّل بعد. الدفع محفوظ، يرجى التواصل مع الدعم.');
        $key = hash('sha256', $order->id.'|'.$order->guest_session_id.'|'.$phone.'|'.strtolower($data['request_id']));
        $challenge = DB::transaction(function () use ($request,$order,$phone,$key,$data): array {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->held($locked);
            $this->assertEligiblePaid($locked);
            abort_unless(($locked->pending_contact_phone ?: $locked->contact_phone) === $phone, 409);
            $duplicate = PhoneVerification::query()->where('send_idempotency_key', $key)->first();
            if ($duplicate) return ['challenge' => $duplicate, 'send' => false];
            $last = PhoneVerification::query()->where('order_id', $locked->id)
                ->where('purpose', self::PURPOSE)->latest('id')->first();
            abort_if($last?->resend_available_at?->isFuture(), 429,
                'انتظر قبل إعادة الإرسال.');
            abort_if($last?->locked_until?->isFuture(), 429);
            $hash = hash_hmac('sha256', $phone, (string) config('app.key'));
            DB::table('phone_verification_send_counters')->insertOrIgnore([
                'phone_hash' => $hash, 'send_date' => now()->toDateString(),
                'sends' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $counter = DB::table('phone_verification_send_counters')
                ->where('phone_hash', $hash)->where('send_date', now()->toDateString())
                ->lockForUpdate()->first();
            abort_if(! $counter || $counter->sends >= (int) config('postpay_otp.max_daily_sends', 4), 429);
            $this->revokePending($locked);
            $row = PhoneVerification::query()->create([
                'order_id' => $locked->id, 'guest_session_id' => $locked->guest_session_id,
                'phone' => $phone, 'purpose' => self::PURPOSE,
                'status' => PhoneVerification::STATUS_DISPATCHING, 'attempts' => 0,
                // Dummy hash: actual OTP is never generated or stored in Laravel.
                'code_hash' => Hash::make(Str::random(64)), 'code_length' => 4,
                'expires_at' => now()->addMinutes(5),
                'resend_available_at' => now()->addSeconds((int) config('postpay_otp.resend_cooldown_seconds', 90)),
                'send_idempotency_key' => $key, 'device_hash' => hash('sha256', $data['device_id']),
                'request_ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            ]);
            DB::table('phone_verification_send_counters')->where('id', $counter->id)
                ->increment('sends', 1, ['updated_at' => now()]);
            return ['challenge' => $row, 'send' => true];
        }, 3);
        $row = $challenge['challenge'];
        if (! $challenge['send']) {
            abort_unless($row->status === PhoneVerification::STATUS_PENDING && $row->sent_at,
                409, 'نتيجة الإرسال السابق غير مؤكدة. لا تعاود الإرسال تلقائيًا.');
            return response()->json(['data' => $this->state($order->fresh())]);
        }
        // Network operation is deliberately OUTSIDE the DB transaction.
        $sent = $this->tawked->startVerification($phone, $key);
        $row->refresh();
        // Editing number while provider call was in flight revokes this challenge.
        if ($row->status !== PhoneVerification::STATUS_DISPATCHING) {
            abort(409, 'تغير رقم الجوال أثناء الإرسال. الرمز السابق غير صالح.');
        }
        if (! $sent->accepted) {
            $row->update(['status' => PhoneVerification::STATUS_FAILED,
                'provider' => $sent->provider, 'failure_code' => $sent->failureCode]);
            abort(503, 'تعذر تأكيد الإرسال. الدفع محفوظ، يمكنك التواصل مع الدعم.');
        }
        $row->update(['status' => PhoneVerification::STATUS_PENDING, 'sent_at' => now(),
            'provider' => $sent->provider, 'provider_message_id' => $sent->messageId]);
        return response()->json(['data' => $this->state($order->fresh())], 201);
    }

    public function check(Request $request, Order $order): JsonResponse
    {
        $this->enabled();
        $data = $request->validate([
            'device_id' => ['required','string','max:191'],
            'verification_id' => ['required','integer'], 'code' => ['required','digits:4'],
        ]);
        $this->owns($request, $order, $data['device_id']);
        $this->assertEligiblePaid($order);
        if ($order->contact_verification_state === 'verified') {
            return response()->json(['data' => $this->state($order->fresh())]);
        }
        $this->held($order);
        $row = PhoneVerification::query()->whereKey($data['verification_id'])
            ->where('order_id', $order->id)->where('guest_session_id', $order->guest_session_id)
            ->where('purpose', self::PURPOSE)->firstOrFail();
        abort_unless(hash_equals((string) $row->device_hash, hash('sha256', $data['device_id'])), 403);
        abort_unless($row->status === PhoneVerification::STATUS_PENDING && $row->expires_at?->isFuture()
            && $row->provider_message_id && ($order->pending_contact_phone ?: $order->contact_phone) === $row->phone,
            422, 'انتهت صلاحية التحقق أو تغيّر رقم الجوال.');
        // Claim an attempt while holding a row lock BEFORE the network call.
        // A transport ambiguity consumes one attempt (fail-closed), so parallel
        // check requests cannot bypass the 3-attempt limit.
        DB::transaction(function () use ($row, $order): void {
            $freshOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->held($freshOrder);
            $fresh = PhoneVerification::query()->lockForUpdate()->findOrFail($row->id);
            abort_unless($fresh->status === PhoneVerification::STATUS_PENDING
                && $fresh->expires_at?->isFuture()
                && (int) $fresh->attempts < 3
                && ($freshOrder->pending_contact_phone ?: $freshOrder->contact_phone) === $fresh->phone,
                429, 'عدد المحاولات غير متاح أو انتهت صلاحية الرمز.');
            $fresh->update(['attempts' => (int) $fresh->attempts + 1,
                'last_attempt_at' => now()]);
        }, 3);
        $answer = $this->tawked->checkVerification((string) $row->provider_message_id, $data['code']);
        abort_unless($answer['ok'], 503, 'تعذّر التحقق لدى توكيد، والدفع محفوظ.');
        if (! $answer['verified']) {
            DB::transaction(function () use ($row, $answer): void {
                $fresh = PhoneVerification::query()->lockForUpdate()->find($row->id);
                if (! $fresh || $fresh->status !== PhoneVerification::STATUS_PENDING) return;
                $attempts = (int) $fresh->attempts;
                $locked = $attempts >= 3 || in_array($answer['status'],
                    ['expired','canceled','too_many_attempts'], true);
                $fresh->update(['attempts' => $attempts, 'last_attempt_at' => now(),
                    'status' => $locked ? PhoneVerification::STATUS_LOCKED : PhoneVerification::STATUS_PENDING,
                    'locked_until' => $locked ? now()->addMinutes(15) : null]);
            }, 3);
            return response()->json(['message' => 'الرمز غير صحيح أو منتهي الصلاحية.'], 422);
        }
        DB::transaction(function () use ($order, $row): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->held($locked);
            $this->assertEligiblePaid($locked);
            $challenge = PhoneVerification::query()->lockForUpdate()->findOrFail($row->id);
            abort_unless($challenge->status === PhoneVerification::STATUS_PENDING && $challenge->expires_at?->isFuture()
                && ($locked->pending_contact_phone ?: $locked->contact_phone) === $challenge->phone,
                409, 'لا يمكن تحرير الطلب بهذا الرمز.');
            $challenge->update(['status' => PhoneVerification::STATUS_VERIFIED, 'verified_at' => now(),
                'verification_expires_at' => now()->addDays(10)]);
            $locked->update(['contact_phone' => $challenge->phone, 'pending_contact_phone' => null,
                'contact_verification_state' => 'verified', 'contact_verified_at' => now(),
                'contact_released_at' => $locked->contact_released_at ?: now()]);
        }, 3);
        $this->welcome($order);
        return response()->json(['data' => $this->state($order->fresh())]);
    }

    private function state(Order $order): array
    {
        $phone = (string) ($order->pending_contact_phone ?: $order->contact_phone);
        $row = PhoneVerification::query()->where('order_id', $order->id)
            ->where('purpose', self::PURPOSE)->where('phone', $phone)->latest('id')->first();
        $verified = $order->contact_verification_state === 'verified';
        $live = $this->eligiblePaid($order);
        return [
            'order_id' => $order->id, 'payment_status' => $order->payment_status,
            'paid' => $order->isPaid(), 'otp_payment_authorized' => $live,
            'verified' => $verified, 'requires_otp' => $order->isPaid() && ! $verified,
            'phone' => $phone, 'release_state' => $verified ? 'released' : 'held',
            'can_send' => ! $verified && $live && config('postpay_otp.allow_live_sms', false)
                && $this->tawked->ready() && (! $row || ! $row->resend_available_at?->isFuture()),
            'resend_after_seconds' => $row?->resend_available_at?->isFuture()
                ? max(0, (int) now()->diffInSeconds($row->resend_available_at)) : 0,
            'challenge' => $row?->status === PhoneVerification::STATUS_PENDING && $row->expires_at?->isFuture()
                ? ['verification_id' => $row->id, 'expires_in_seconds' => max(0, (int) now()->diffInSeconds($row->expires_at))] : null,
        ];
    }

    private function release(Order $order, string $phone): void
    {
        DB::transaction(function () use ($order, $phone): void {
            $fresh = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertEligiblePaid($fresh);
            $this->held($fresh);
            abort_unless(($fresh->pending_contact_phone ?: $fresh->contact_phone) === $phone, 409);
            $fresh->update(['contact_phone' => $phone, 'pending_contact_phone' => null,
                'contact_verification_state' => 'verified', 'contact_verified_at' => now(),
                'contact_released_at' => $fresh->contact_released_at ?: now()]);
        }, 3);
        $this->welcome($order);
    }

    private function welcome(Order $order): void
    {
        try {
            $this->notifications->persistPhoneVerifiedWelcome(null, $order->guest_session_id,
                'postpay-order:'.$order->id);
        } catch (\Throwable $e) { report($e); }
    }

    private function revokePending(Order $order): void
    {
        PhoneVerification::query()->where('order_id', $order->id)
            ->where('purpose', self::PURPOSE)->whereNull('verified_at')
            ->whereIn('status', [PhoneVerification::STATUS_PENDING, PhoneVerification::STATUS_DISPATCHING])
            ->update(['status' => PhoneVerification::STATUS_REVOKED, 'revoked_at' => now()]);
    }

    private function owns(Request $request, Order $order, string $deviceId): void
    {
        $sessionId = trim((string) $request->header('X-Guest-Session', ''));
        abort_unless($sessionId !== '' && $order->guest_session_id !== null
            && hash_equals((string) $order->guest_session_id, $sessionId), 403);
        $this->guests->authenticate($sessionId, $this->guests->tokenFromRequest($request), $deviceId);
    }

    private function enabled(): void
    {
        abort_unless((bool) config('postpay_otp.enabled', false), 404);
    }

    private function held(Order $order): void
    {
        abort_unless($order->isPaid() && $order->contact_verification_state === 'pending'
            && ! $order->isCancelled(), 409, 'هذا الطلب ليس مدفوعًا ومعلّقًا بانتظار التوثيق.');
    }

    /** Sandbox mode is explicitly isolated and may only send to the Tawked account owner. */
    private function sandboxOtpTest(): bool
    {
        return (string) config('app.env') === 'staging'
            && (bool) config('postpay_otp.staging_sandbox_sms_enabled', false)
            && (string) config('zad_tawked.expected_mode') === 'test'
            && str_starts_with((string) config('zad_tawked.api_key'), 'tk_test_')
            && preg_match('/^05[0-9]{8}$/D', (string) config('postpay_otp.staging_owner_phone', '')) === 1;
    }

    private function assertAllowedOtpDestination(Order $order, string $phone): void
    {
        if (! $this->sandboxOtpTest()) return;
        // If the only confirmed payment proof is sandbox, only the account owner may receive SMS.
        if (! $this->livePaymentProof($order)) {
            abort_unless(hash_equals((string) config('postpay_otp.staging_owner_phone'), $phone),
                403, 'هذا الرقم غير مسموح في اختبار توكيد التجريبي.');
        }
    }

    private function assertEligiblePaid(Order $order): void
    {
        abort_unless($this->eligiblePaid($order), 409,
            'يلزم دفع مؤكد من ميسر مطابق لنفس الطلب، أو اختبار Sandbox مقيد على Staging.');
    }

    private function eligiblePaid(Order $order): bool
    {
        return $this->livePaymentProof($order)
            || ($this->sandboxOtpTest() && $this->matchedPaymentProof($order, 'moyasar_sandbox'));
    }

    private function livePaymentProof(Order $order): bool
    {
        return $this->matchedPaymentProof($order, 'moyasar_live');
    }

    private function matchedPaymentProof(Order $order, string $gateway): bool
    {
        if (! $order->isPaid() || $order->isCancelled()) return false;
        $expectedHalalas = (int) round((float) $order->total * 100);
        if ($expectedHalalas <= 0) return false;
        $attempt = PaymentAttempt::query()->where('order_id', $order->id)
            ->where('gateway', $gateway)->where('status', 'paid')
            ->where('amount_halalas', $expectedHalalas)->where('currency', 'SAR')
            ->whereNotNull('paid_at')->whereNotNull('provider_payment_id')->latest('id')->first();
        if ($attempt === null) return false;
        // Local simulation is NOT a sandbox payment: gateway must be moyasar_sandbox.
        $payment = Payment::query()->where('order_id', $order->id)
            ->where('provider_reference', $attempt->provider_payment_id)
            ->where('currency', 'SAR')->where('status', 'paid')->first();
        return $payment !== null
            && (int) round((float) $payment->gross_amount * 100) === $expectedHalalas;
    }
}
