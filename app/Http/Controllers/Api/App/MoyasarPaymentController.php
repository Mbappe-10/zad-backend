<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Services\App\MoyasarModeResolver;
use App\Services\App\PlatformOperationGate;
use App\Services\LaunchAttributionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MoyasarPaymentController extends Controller
{
    public function __construct(
        private readonly LaunchAttributionService $launchAttribution,
        private readonly PlatformOperationGate $operations,
        private readonly MoyasarModeResolver $modes,
    ) {
    }

    public function attempt(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if ($order->isPaid()) {
            return response()->json([
                'message' => 'الطلب مدفوع مسبقًا.',
                'data' => [
                    'paid' => true,
                    'order' => $this->orderSummary($order),
                ],
            ]);
        }

        $this->operations->assertNewPaymentAttemptAllowed();
        abort_if($order->isCancelled(), 422, 'لا يمكن دفع طلب ملغي أو مرفوض.');

        $mode = $this->modes->currentMode();
        $this->modes->assertPermitted($mode);
        $this->assertV5PaymentMode($order, $mode);
        $credentials = $mode === 'simulation' ? null : $this->modes->credentials($mode);
        $localTest = $mode === 'simulation';
        $amountHalalas = $this->amountHalalas($order);
        abort_if($amountHalalas <= 0, 422, 'مبلغ الطلب غير صالح للدفع.');
        $gateway = $this->modes->gateway($mode);

        $attempt = PaymentAttempt::query()
            ->where('order_id', $order->id)
            ->where('gateway', $gateway)
            ->where('status', 'created')
            ->where('amount_halalas', $amountHalalas)
            ->where('currency', 'SAR')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->latest('id')
            ->first();

        if ($attempt === null) {
            $attempt = PaymentAttempt::query()->create([
                'order_id' => $order->id,
                'idempotency_key' => (string) Str::uuid(),
                'gateway' => $gateway,
                'status' => 'created',
                'amount_halalas' => $amountHalalas,
                'currency' => 'SAR',
            ]);
        }

        if ($order->payment_status !== Order::PAYMENT_PENDING) {
            $order->update(['payment_status' => Order::PAYMENT_PENDING]);
        }

        return response()->json([
            'message' => 'تم تجهيز محاولة الدفع.',
            'data' => [
                'paid' => false,
                'publishable_key' => $credentials['publishable_key'] ?? '',
                'amount_halalas' => $amountHalalas,
                'currency' => 'SAR',
                'description' => "ZAD Sync order {$order->number}",
                'given_id' => (string) $attempt->idempotency_key,
                'metadata' => [
                    'order_id' => (string) $order->id,
                    'order_number' => (string) $order->number,
                    'environment' => $mode,
                ],
                'apple_pay_merchant_id' => $localTest
                    ? ''
                    : trim((string) config('moyasar.apple_pay_merchant_id', '')),
                'apple_pay_label' => (string) config('moyasar.apple_pay_label', 'ZAD Sync'),
                'local_test_enabled' => $localTest,
                'order' => $this->orderSummary($order->fresh()),
            ],
        ]);
    }

    /**
     * Create a single-use Moyasar Payment Intent for the Web only.
     * Does not alter native Android or the existing payment/attempt endpoint.
     * IMPORTANT: activate ZAD_WEB_INTENTS_ENABLED only after end-to-end tests.
     */
    /**
     * Single-use payment intent. A web caller may only use the server-issued
     * given_id returned from the existing payment/attempt endpoint.
     * Native checkout, settlement, OTP and webhook logic remain unchanged.
     */
    public function webIntent(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        abort_unless((bool) config('zad_web_payment.enabled', false), 503, 'Web payment intents are not enabled.');
        abort_unless($this->isV5PostpayOrder($order), 422, 'Web checkout requires V5 postpay.');
        $this->operations->assertNewPaymentAttemptAllowed();
        abort_if($order->isCancelled() || $order->isPaid(), 422, 'Order cannot be charged.');

        // The same live-mode enforcement used by native payment/attempt.
        $mode = $this->modes->currentMode();
        $this->modes->assertPermitted($mode);
        $this->assertV5PaymentMode($order, $mode);
        abort_unless($mode === 'live', 503, 'Production web payments require live mode.');
        $credentials = $this->modes->credentials('live');
        $secretKey = $credentials['secret_key'];

        $validated = $request->validate(['given_id' => ['required', 'uuid']]);
        $givenId = (string) $validated['given_id'];
        $amount = $this->amountHalalas($order);
        abort_unless($amount >= 100, 422, 'Invalid payment amount.');
        $callback = trim((string) config('zad_web_payment.callback_url', ''));
        abort_unless(
            $callback === 'https://zad-sync-sa.onrender.com/zad-moyasar-return.html',
            503,
            'Payment callback is not configured for this release.',
        );
        // An HTTPS literal prevents config-injected HTTP destinations for a
        // secret-bearing request. This also matches native provider verification.
        $apiBase = 'https://api.moyasar.com/v1';

        // Reserve the original attempt atomically, before any remote I/O.
        $reservation = DB::transaction(function () use ($order, $givenId, $amount): array {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_if($lockedOrder->isCancelled() || $lockedOrder->isPaid(), 422, 'Order cannot be charged.');
            abort_unless($this->amountHalalas($lockedOrder) === $amount, 409, 'Order amount changed. Refresh checkout.');

            $attempt = PaymentAttempt::query()
                ->where('order_id', $lockedOrder->id)
                ->where('gateway', 'moyasar_live')
                ->where('idempotency_key', $givenId)
                ->where('status', 'created')
                ->where('currency', 'SAR')
                ->where('amount_halalas', $amount)
                ->where('created_at', '>=', now()->subMinutes(15))
                ->lockForUpdate()->first();
            abort_unless($attempt !== null, 409, 'Unknown or expired attempt. Check order payment status.');

            // Reject a concurrent intent from another checkout tab.
            $otherAttempts = PaymentAttempt::query()
                ->where('order_id', $lockedOrder->id)
                ->where('gateway', 'moyasar_live')
                ->where('status', 'created')
                ->where('id', '!=', $attempt->id)
                ->where('created_at', '>=', now()->subMinutes(60))
                ->get();
            foreach ($otherAttempts as $other) {
                $otherPayload = is_array($other->payload) ? $other->payload : [];
                if (! empty($otherPayload['web_intent_id']) ||
                    in_array($otherPayload['web_intent_state'] ?? '', ['creating', 'ready'], true)) {
                    abort(409, 'Another payment session exists. Check payment status.');
                }
            }

            $payload = is_array($attempt->payload) ? $attempt->payload : [];
            if (! empty($payload['web_intent_id'])) {
                abort_unless(($payload['web_intent_state'] ?? 'ready') === 'ready',
                    409, 'Payment session requires reconciliation.');
                return [
                    'action' => 'reuse', 'attempt_id' => $attempt->id,
                    'intent_id' => (string) $payload['web_intent_id'],
                    'client_secret_enc' => (string) ($payload['web_client_secret_enc'] ?? ''),
                ];
            }
            // Unknown HTTP outcome is never retried under the same order.
            abort_if(isset($payload['web_intent_state']),
                409, 'Payment session needs reconciliation before any retry.');

            $attempt->update(['payload' => array_merge($payload, [
                'web_intent_state' => 'creating',
                'web_intent_reserved_at' => now()->toIso8601String(),
            ])]);
            if ($lockedOrder->payment_status !== Order::PAYMENT_PENDING) {
                $lockedOrder->update(['payment_status' => Order::PAYMENT_PENDING]);
            }
            return ['action' => 'create', 'attempt_id' => $attempt->id];
        });

        $attempt = PaymentAttempt::query()->findOrFail($reservation['attempt_id']);
        if ($reservation['action'] === 'reuse') {
            $intentId = (string) $reservation['intent_id'];
            try {
                $state = Http::acceptJson()->withBasicAuth($secretKey, '')
                    ->timeout(15)->get($apiBase.'/payment_intents/'.rawurlencode($intentId));
            } catch (ConnectionException) {
                abort(503, 'Could not verify existing intent. Check payment status.');
            }
            abort_unless($state->successful(), 503, 'Payment intent status unavailable.');
            $intent = $state->json();
            abort_unless(is_array($intent) && ($intent['status'] ?? '') === 'created',
                409, 'Payment already initiated. Check status before retrying.');
            $expiresAt = trim((string) ($intent['expires_at'] ?? ''));
            abort_unless($expiresAt !== '' && \Carbon\Carbon::parse($expiresAt)->isFuture(),
                409, 'Payment session expired. Check payment status.');
            abort_unless((string) ($intent['id'] ?? '') === $intentId &&
                (int) ($intent['amount'] ?? -1) === $amount &&
                strtoupper((string) ($intent['currency'] ?? '')) === 'SAR' &&
                (string) ($intent['callback_url'] ?? '') === $callback,
                503, 'Existing intent does not match order.');
            $encrypted = (string) $reservation['client_secret_enc'];
            abort_unless($encrypted !== '', 503, 'Incomplete active payment intent.');
            try {
                $clientSecret = \Illuminate\Support\Facades\Crypt::decryptString($encrypted);
            } catch (\Throwable) {
                abort(503, 'Payment intent cannot be restored.');
            }
            return $this->webIntentResponse($order, $attempt, $intentId, $clientSecret);
        }

        // Remote creation is deliberately OUTSIDE the database transaction.
        // Any ambiguous response leaves the reservation locked for reconciliation.
        try {
            $response = Http::acceptJson()->withBasicAuth($secretKey, '')
                ->timeout(20)->post($apiBase.'/payment_intents', [
                    'amount' => $amount,
                    'currency' => 'SAR',
                    'description' => 'ZAD Sync order '.$order->number,
                    'callback_url' => $callback,
                ]);
        } catch (ConnectionException) {
            abort(503, 'Intent response unknown. Reconcile before trying again.');
        }
        abort_unless($response->status() === 201, 503, 'Intent creation not confirmed. Reconcile before retrying.');
        $intent = $response->json();
        abort_unless(is_array($intent), 503, 'Unexpected intent response. Reconcile before retrying.');
        $intentId = trim((string) ($intent['id'] ?? ''));
        $clientSecret = trim((string) ($intent['client_secret'] ?? ''));
        abort_unless(
            Str::isUuid($intentId) && str_starts_with($clientSecret, 'pi_secret_') &&
            ($intent['status'] ?? '') === 'created' &&
            (int) ($intent['amount'] ?? 0) === $amount &&
            strtoupper((string) ($intent['currency'] ?? '')) === 'SAR' &&
            ($intent['callback_url'] ?? '') === $callback,
            503, 'Intent response failed validation. Reconcile before retrying.',
        );
        DB::transaction(function () use ($attempt, $amount, $intent, $intentId, $clientSecret): void {
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $payload = is_array($locked->payload) ? $locked->payload : [];
            abort_unless($locked->status === 'created' &&
                (int) $locked->amount_halalas === $amount &&
                ($payload['web_intent_state'] ?? '') === 'creating',
                409, 'Checkout attempt changed while creating intent. Reconcile before retrying.');
            $locked->update(['payload' => array_merge($payload, [
                'web_intent_state' => 'ready',
                'web_intent_id' => $intentId,
                'web_intent_expires_at' => (string) ($intent['expires_at'] ?? ''),
                'web_client_secret_enc' => \Illuminate\Support\Facades\Crypt::encryptString($clientSecret),
            ])]);
        });
        return $this->webIntentResponse($order, $attempt, $intentId, $clientSecret);
    }

    private function webIntentResponse(Order $order, PaymentAttempt $attempt, string $intentId, string $clientSecret): JsonResponse
    {
        return response()->json([
            'data' => [
                // Accessing credentials here also re-checks the LIVE master switch.
                'publishable_key' => $this->modes->credentials('live')['publishable_key'],
                'amount_halalas' => $this->amountHalalas($order),
                'currency' => 'SAR',
                'description' => 'ZAD Sync order '.$order->number,
                'given_id' => (string) $attempt->idempotency_key,
                'metadata' => [
                    'order_id' => (string) $order->id,
                    'order_number' => (string) $order->number,
                    'environment' => 'live',
                ],
                'intent_id' => $intentId,
                'client_secret' => $clientSecret,
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function verify(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        $data = $request->validate(['payment_id' => ['required', 'uuid']]);

        if ($order->isPaid()) {
            return $this->paidResponse($order->fresh());
        }

        // Moyasar gives the created payment exactly the caller's given_id.
        // Never verify an arbitrary payment ID that has no matching attempt.
        $attempt = $this->knownAttempt($order, (string) $data['payment_id']);
        abort_unless($attempt !== null, 422, 'لم يتم العثور على محاولة دفع لهذا الطلب.');
        $mode = $this->modes->modeForGateway((string) $attempt->gateway);
        abort_unless(in_array($mode, ['live', 'sandbox'], true), 422, 'محاولة الدفع غير صالحة.');
        $this->assertV5PaymentMode($order, $mode);

        $payment = $this->fetchPayment((string) $data['payment_id'], $mode);
        $this->assertPaymentMatches($order, $payment, $attempt, $mode);
        $order = $this->markPaid($order, $payment, $attempt, $mode);

        return $this->paidResponse($order);
    }

    public function status(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        return response()->json([
            'data' => [
                'paid' => $order->isPaid(),
                'payment_status' => $order->payment_status,
                'order' => $this->orderSummary($order),
            ],
        ]);
    }

    public function localTest(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        abort_unless($this->modes->currentMode() === 'simulation', 404);
        $this->modes->assertPermitted('simulation');

        if ($order->isPaid()) {
            return $this->paidResponse($order);
        }

        $this->operations->assertNewPaymentAttemptAllowed();
        abort_if($order->isCancelled(), 422, 'لا يمكن دفع طلب ملغي أو مرفوض.');

        $attempt = PaymentAttempt::query()
            ->where('order_id', $order->id)
            ->where('gateway', 'local_test')
            ->where('status', 'created')
            ->where('amount_halalas', $this->amountHalalas($order))
            ->latest('id')
            ->first();

        abort_unless($attempt !== null, 422, 'يجب تجهيز محاولة محاكاة جديدة قبل تأكيدها.');

        $payload = [
            'id' => 'local_'.Str::uuid(),
            'status' => 'paid',
            'amount' => $this->amountHalalas($order),
            'currency' => 'SAR',
            'metadata' => [
                'order_id' => (string) $order->id,
                'order_number' => (string) $order->number,
                'environment' => 'simulation',
            ],
        ];

        return $this->paidResponse($this->markPaid($order, $payload, $attempt, 'simulation'));
    }

    public function webhook(Request $request): JsonResponse
    {
        $expectedSecret = trim((string) config('moyasar.webhook_secret', ''));
        $receivedSecret = trim((string) $request->input('secret_token', ''));
        abort_if(
            $expectedSecret === '' || $receivedSecret === '' ||
            ! hash_equals($expectedSecret, $receivedSecret),
            401,
            'Invalid webhook secret.',
        );

        $type = (string) $request->input('type', '');
        if (! in_array($type, ['payment_paid', 'payment_faild', 'payment_failed'], true)) {
            return response()->json(['received' => true]);
        }

        // Required in Moyasar webhook envelope. Do not trust arbitrary data.live.
        $liveFlag = $request->input('live');
        abort_unless(is_bool($liveFlag), 422, 'Invalid payment environment.');
        $mode = $liveFlag ? 'live' : 'sandbox';
        $this->modes->assertPermitted($mode);

        $payload = $request->input('data');
        if (! is_array($payload)) {
            return response()->json(['received' => true]);
        }

        $orderId = (int) data_get($payload, 'metadata.order_id', 0);
        $paymentId = (string) ($payload['id'] ?? '');
        if ($orderId <= 0 || ! Str::isUuid($paymentId)) {
            return response()->json(['received' => true]);
        }

        $order = Order::query()->find($orderId);
        if ($order === null) {
            return response()->json(['received' => true]);
        }

        $attempt = $this->knownAttempt($order, $paymentId);
        if ($attempt === null || $attempt->gateway !== $this->modes->gateway($mode)) {
            return response()->json(['received' => true]);
        }
        $this->assertV5PaymentMode($order, $mode);


        // A shared webhook secret is not proof of payment. Fetch from Moyasar
        // with the secret key bound to the original attempt's environment.
        $payment = $this->fetchPayment($paymentId, $mode);

        if ($type === 'payment_paid') {
            $this->assertPaymentMatches($order, $payment, $attempt, $mode);
            $this->markPaid($order, $payment, $attempt, $mode);
        } elseif (($payment['status'] ?? null) === 'failed') {
            DB::transaction(function () use ($order, $attempt, $payment): void {
                $lockedOrder = Order::query()->lockForUpdate()->find($order->id);
                if ($lockedOrder === null || $lockedOrder->isPaid()) {
                    return;
                }

                $lockedAttempt = PaymentAttempt::query()
                    ->whereKey($attempt->id)
                    ->lockForUpdate()
                    ->first();
                if ($lockedAttempt === null || $lockedAttempt->status === 'paid') {
                    return;
                }

                $lockedAttempt->update([
                    'status' => 'failed',
                    'failure_message' => (string) data_get($payment, 'source.message', 'تعذر الدفع.'),
                    'payload' => $payment,
                    'failed_at' => now(),
                ]);
                $lockedOrder->update(['payment_status' => Order::PAYMENT_FAILED]);
            });
        }

        return response()->json(['received' => true]);
    }

    private function knownAttempt(Order $order, string $paymentId): ?PaymentAttempt
    {
        if (! Str::isUuid($paymentId)) {
            return null;
        }

        return PaymentAttempt::query()
            ->where('order_id', $order->id)
            ->where('idempotency_key', $paymentId)
            ->whereIn('gateway', ['moyasar_live', 'moyasar_sandbox'])
            ->first();
    }

    private function fetchPayment(string $paymentId, string $mode): array
    {
        // Credential selection comes ONLY from the persisted attempt gateway.
        $credentials = $this->modes->credentials($mode);

        try {
            $response = Http::acceptJson()
                ->withBasicAuth($credentials['secret_key'], '')
                ->timeout(20)
                ->retry(2, 250)
                ->get('https://api.moyasar.com/v1/payments/'.rawurlencode($paymentId));
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'payment' => ['تعذر الاتصال بميسر. يرجى التحقق من حالة الدفع لاحقًا.'],
            ]);
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw ValidationException::withMessages([
                'payment' => ['تعذر التحقق من عملية الدفع لدى ميسر.'],
            ]);
        }

        return $response->json();
    }

    private function assertPaymentMatches(
        Order $order,
        array $payment,
        PaymentAttempt $attempt,
        string $mode,
    ): void {
        if (($payment['status'] ?? null) !== 'paid') {
            throw ValidationException::withMessages([
                'payment' => [$this->failureMessage($payment)],
            ]);
        }

        $paymentId = (string) ($payment['id'] ?? '');
        if (
            $attempt->order_id != $order->id ||
            $attempt->gateway !== $this->modes->gateway($mode) ||
            ! hash_equals((string) $attempt->idempotency_key, $paymentId) ||
            (int) ($payment['amount'] ?? -1) !== $this->amountHalalas($order) ||
            (int) $attempt->amount_halalas !== $this->amountHalalas($order) ||
            strtoupper((string) ($payment['currency'] ?? '')) !== 'SAR' ||
            (int) data_get($payment, 'metadata.order_id', 0) !== (int) $order->id ||
            (string) data_get($payment, 'metadata.environment', '') !== $mode
        ) {
            throw ValidationException::withMessages([
                'payment' => ['بيانات العملية أو بيئتها لا تطابق الطلب.'],
            ]);
        }

        // Some Moyasar payment responses include live; if present it must match.
        if (array_key_exists('live', $payment) &&
            (! is_bool($payment['live']) || $payment['live'] !== ($mode === 'live'))
        ) {
            throw ValidationException::withMessages([
                'payment' => ['بيئة عملية الدفع لا تطابق بيئة الطلب.'],
            ]);
        }
    }

    private function markPaid(Order $order, array $payment, PaymentAttempt $attempt, string $mode): Order
    {
        return DB::transaction(function () use ($order, $payment, $attempt, $mode): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->isPaid()) {
                return $order;
            }

            // Re-evaluate the V5 payment environment inside the locked transaction.
            $this->assertV5PaymentMode($order, $mode);
            // Re-evaluate against the locked order to prevent coupon/amount races.
            if ($mode === 'simulation') {
                $this->modes->assertPermitted('simulation');
                abort_unless(str_starts_with((string) ($payment['id'] ?? ''), 'local_'), 422);
                abort_unless($attempt->gateway === 'local_test', 422);
                abort_unless(
                    (int) $attempt->amount_halalas === $this->amountHalalas($order) &&
                    (int) ($payment['amount'] ?? -1) === $this->amountHalalas($order) &&
                    (int) data_get($payment, 'metadata.order_id', 0) === (int) $order->id &&
                    ($payment['status'] ?? '') === 'paid',
                    422,
                );
            } else {
                $this->modes->assertPermitted($mode);
                $this->assertPaymentMatches($order, $payment, $attempt, $mode);
            }

            $attempt = PaymentAttempt::query()
                ->whereKey($attempt->id)
                ->where('order_id', $order->id)
                ->where('gateway', $this->modes->gateway($mode))
                ->lockForUpdate()
                ->firstOrFail();

            $paymentId = (string) ($payment['id'] ?? '');
            abort_if($paymentId === '', 422, 'معرّف الدفع مفقود.');

            $attempt->update([
                'provider_payment_id' => $paymentId,
                'status' => 'paid',
                'payload' => $payment,
                'failure_message' => null,
                'paid_at' => now(),
                'failed_at' => null,
            ]);

            $providerCode = match ($mode) {
                'live' => 'moyasar',
                'sandbox' => 'moyasar_sandbox',
                'simulation' => 'local_test',
            };

            $provider = PaymentProvider::query()->firstOrCreate(
                ['code' => $providerCode],
                [
                    'name' => match ($mode) {
                        'live' => 'Moyasar',
                        'sandbox' => 'Moyasar Sandbox',
                        'simulation' => 'Local Test',
                    },
                    'currency' => 'SAR',
                    'is_active' => $mode === 'live',
                    'settings' => ['status' => $mode === 'live' ? 'active' : 'inactive'],
                ],
            );

            $grossAmount = round(((int) ($payment['amount'] ?? 0)) / 100, 2);
            $providerFee = round(max(0, ((int) ($payment['fee'] ?? 0)) / 100), 2);

            Payment::query()->updateOrCreate(
                ['provider_reference' => $paymentId],
                [
                    'order_id' => $order->id,
                    'provider_id' => $provider->id,
                    'reference' => 'MYS-'.$order->id.'-'.Str::upper(substr(hash('sha256', $paymentId), 0, 12)),
                    'method' => (string) data_get($payment, 'source.type', 'gateway'),
                    'currency' => 'SAR',
                    'gross_amount' => $grossAmount,
                    'provider_fee' => $providerFee,
                    'net_amount' => round($grossAmount - $providerFee, 2),
                    'status' => 'paid',
                    'paid_at' => now(),
                    'failed_at' => null,
                    'metadata' => [
                        'gateway' => $attempt->gateway,
                        'payment_mode' => $mode,
                        'attempt_id' => $attempt->id,
                        'fee_reported_by_provider' => array_key_exists('fee', $payment),
                    ],
                ],
            );

            $order->update(['payment_status' => Order::PAYMENT_PAID]);

            \App\Models\OrderCouponUsage::query()
                ->where('order_id', $order->id)
                ->where('status', 'applied')
                ->update(['status' => 'consumed']);

            $this->launchAttribution->markOrderPaid($order);

            return $order->fresh(['items', 'store']);
        });
    }

    private function isV5PostpayOrder(Order $order): bool
    {
        return trim((string) ($order->checkout_request_id ?? '')) !== '';
    }

    /** Preserve legacy clients while enforcing signed ownership for new V5 orders. */
    private function assertV5PaymentMode(Order $order, string $mode): void
    {
        if (! $this->isV5PostpayOrder($order)) {
            return;
        }

        // Production accepts only real money; isolated staging may use sandbox.
        $allowed = ($mode === 'live' && app()->environment('production'))
            || ($mode === 'sandbox' && app()->environment('staging')
                && (bool) config('moyasar.non_live_isolated', false));

        abort_unless($allowed, 503, 'وضع الدفع غير مسموح لطلبات V5.');
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        if ($this->isV5PostpayOrder($order)) {
            app(\App\Services\App\OrderGuestAccess::class)->assert($request, $order);
            return;
        }

        $guestSessionId = trim((string) $request->header('X-Guest-Session', ''));
        $guestAllowed = $guestSessionId !== '' &&
            hash_equals((string) $order->guest_session_id, $guestSessionId);

        $user = $request->user();
        $customerAllowed = $user !== null &&
            $order->customer_id !== null &&
            $order->customer_id === $user->appProfile?->customer_id;

        abort_unless(
            $guestAllowed || $customerAllowed,
            403,
            'لا يمكنك الوصول إلى دفع هذا الطلب.',
        );
    }

    private function amountHalalas(Order $order): int
    {
        return (int) round(((float) $order->total) * 100);
    }

    private function orderSummary(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'payment_status' => $order->payment_status,
            'subtotal' => (float) $order->subtotal,
            'delivery_fee' => (float) $order->delivery_fee,
            'discount' => (float) $order->discount,
            'tax' => (float) $order->tax,
            'total' => (float) $order->total,
            'contact_phone' => $order->contact_phone,
            'store' => $order->store !== null
                ? [
                    'id' => $order->store->id,
                    'name_ar' => $order->store->name_ar,
                    'name_en' => $order->store->name_en,
                ]
                : null,
        ];
    }

    private function paidResponse(Order $order): JsonResponse
    {
        return response()->json([
            'message' => 'تم الدفع وتأكيد الطلب بنجاح.',
            'data' => ['paid' => true, 'order' => $this->orderSummary($order)],
        ]);
    }

    private function failureMessage(array $payment): string
    {
        $message = trim((string) data_get($payment, 'source.message', ''));
        return $message !== ''
            ? $message
            : 'لم تكتمل عملية الدفع. يمكنك إعادة المحاولة أو تغيير البطاقة.';
    }
}
