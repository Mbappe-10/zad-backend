<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Services\App\GuestSessionAuthenticator;
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
        private readonly GuestSessionAuthenticator $guestSessionAuthenticator,
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

    private function authorizeOrder(Request $request, Order $order): void
    {
        // Authenticated customer keeps the existing customer ownership rule.
        $user = $request->user();
        $customerAllowed = $user !== null &&
            $order->customer_id !== null &&
            $order->customer_id === $user->appProfile?->customer_id;

        if ($customerAllowed) {
            return;
        }

        $guestSessionId = trim((string) $request->header('X-Guest-Session', ''));
        abort_unless(
            $guestSessionId !== '' &&
            $order->guest_session_id !== null &&
            hash_equals((string) $order->guest_session_id, $guestSessionId),
            403,
            'ظ„ط§ ظٹظ…ظƒظ†ظƒ ط§ظ„ظˆطµظˆظ„ ط¥ظ„ظ‰ ط¯ظپط¹ ظ‡ط°ط§ ط§ظ„ط·ظ„ط¨.',
        );

        // Session ID alone never authorizes payment. Check the secret guest token.
        $guestSessionToken = $this->guestSessionAuthenticator->tokenFromRequest($request);
        $this->guestSessionAuthenticator->authenticate($guestSessionId, $guestSessionToken);
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
