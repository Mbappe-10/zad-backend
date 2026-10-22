<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentProvider;
use App\Services\App\PlatformOperationGate;
use App\Services\App\OrderGuestAccess;
use App\Services\App\MoyasarV5LivePaymentPolicy;
use App\Services\LaunchAttributionService;
use App\Support\InternalTesting;
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
        private readonly OrderGuestAccess $orderGuestAccess,
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

        abort_if(
            $order->isCancelled(),
            422,
            'لا يمكن دفع طلب ملغي أو مرفوض.',
        );

        $publishableKey = trim((string) config('moyasar.publishable_key'));
        $localTestEnabled = $this->localTestEnabled($order);

        // V5 postpay orders must be paid with verified LIVE credentials only.
        $v5Postpay = $this->isV5PostpayOrder($order);
        if ($v5Postpay) {
            $this->assertV5LiveCredentials();
        }

        if ($publishableKey === '' && ! $localTestEnabled) {
            throw ValidationException::withMessages([
                'payment' => [
                    'بوابة ميسّر غير مهيأة. أضف مفاتيح ميسّر في إعدادات Laravel.',
                ],
            ]);
        }

        $amountHalalas = $this->amountHalalas($order);
        $attempt = PaymentAttempt::query()
            ->where('order_id', $order->id)
            ->where('gateway', $v5Postpay ? 'moyasar_live' : 'moyasar')
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
                'gateway' => $v5Postpay ? 'moyasar_live' : 'moyasar',
                'status' => 'created',
                'amount_halalas' => $amountHalalas,
                'currency' => 'SAR',
            ]);
        }

        $givenId = (string) $attempt->idempotency_key;

        if ($order->payment_status !== Order::PAYMENT_PENDING) {
            $order->update(['payment_status' => Order::PAYMENT_PENDING]);
        }

        return response()->json([
            'message' => 'تم تجهيز محاولة الدفع.',
            'data' => [
                'paid' => false,
                // A real gateway key must never reach the mobile UI while
                // this order is using the controlled internal-test path.
                'publishable_key' => $localTestEnabled
                    ? ''
                    : $publishableKey,
                'amount_halalas' => $amountHalalas,
                'currency' => 'SAR',
                'description' => "ZAD Sync order {$order->number}",
                'given_id' => $givenId,
                'metadata' => [
                    'order_id' => (string) $order->id,
                    'order_number' => (string) $order->number,
                ],
                'apple_pay_merchant_id' => $localTestEnabled
                    ? ''
                    : trim((string) config(
                        'moyasar.apple_pay_merchant_id',
                    )),
                'apple_pay_label' => (string) config('moyasar.apple_pay_label', 'ZAD Sync'),
                'local_test_enabled' => $localTestEnabled,
                'order' => $this->orderSummary($order->fresh()),
            ],
        ]);
    }

    public function verify(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $data = $request->validate([
            'payment_id' => ['required', 'string', 'max:100'],
        ]);

        if ($order->isPaid()) {
            return $this->paidResponse($order->fresh());
        }

        if ($this->isV5PostpayOrder($order)) {
            $this->assertV5LiveCredentials();
        }
        // fetchPayment uses the SERVER secret and contacts the provider.
        $payment = $this->fetchPayment($data['payment_id']);
        $this->assertPaymentMatches($order, $payment);
        $this->assertV5LiveProof($order, $payment);
        $order = $this->markPaid($order, $payment);

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

        abort_unless($this->localTestEnabled($order), 404);

        if ($order->isPaid()) {
            return $this->paidResponse($order);
        }

        $this->operations->assertNewPaymentAttemptAllowed();

        $payload = [
            'id' => 'local_'.Str::uuid(),
            'status' => 'paid',
            'amount' => $this->amountHalalas($order),
            'currency' => 'SAR',
            'metadata' => [
                'order_id' => (string) $order->id,
                'order_number' => (string) $order->number,
                'environment' => 'internal_test',
            ],
        ];

        $order = $this->markPaid($order, $payload);

        return $this->paidResponse($order);
    }

    public function webhook(Request $request): JsonResponse
    {
        $expectedSecret = trim((string) config('moyasar.webhook_secret'));
        $receivedSecret = trim((string) $request->input('secret_token', ''));

        abort_if(
            $expectedSecret === '' ||
            $receivedSecret === '' ||
            ! hash_equals($expectedSecret, $receivedSecret),
            401,
            'Invalid webhook secret.',
        );

        $type = (string) $request->input('type', '');
        $payment = $request->input('data');

        if (! is_array($payment)) {
            return response()->json(['received' => true]);
        }

        $orderId = (int) data_get($payment, 'metadata.order_id', 0);
        $order = $orderId > 0 ? Order::query()->find($orderId) : null;

        if ($order === null) {
            return response()->json(['received' => true]);
        }

        if ($type === 'payment_paid') {
            // For V5, webhook JSON alone cannot establish real-money provenance.
            // Validate the provider payment with the live SERVER secret before crediting.
            if ($this->isV5PostpayOrder($order)) {
                $this->assertV5LiveCredentials();
                $providerPaymentId = trim((string) ($payment['id'] ?? ''));
                abort_unless($providerPaymentId !== '' && ! str_starts_with($providerPaymentId, 'local_'), 422);
                $payment = $this->fetchPayment($providerPaymentId);
            }
            $this->assertPaymentMatches($order, $payment);
            $this->assertV5LiveProof($order, $payment);
            $this->markPaid($order, $payment);
        }

        if (in_array($type, ['payment_faild', 'payment_failed'], true)) {
            PaymentAttempt::query()
                ->where('provider_payment_id', (string) ($payment['id'] ?? ''))
                ->update([
                    'status' => 'failed',
                    'failure_message' => (string) data_get(
                        $payment,
                        'source.message',
                        'تعذر الدفع.',
                    ),
                    'payload' => $payment,
                    'failed_at' => now(),
                ]);

            if (! $order->isPaid()) {
                $order->update(['payment_status' => Order::PAYMENT_FAILED]);
            }
        }

        return response()->json(['received' => true]);
    }

    private function fetchPayment(string $paymentId): array
    {
        $secretKey = trim((string) config('moyasar.secret_key'));

        if ($secretKey === '') {
            throw ValidationException::withMessages([
                'payment' => [
                    'مفتاح ميسّر السري غير مهيأ في Laravel.',
                ],
            ]);
        }

        try {
            $response = Http::acceptJson()
                ->withBasicAuth($secretKey, '')
                ->timeout(20)
                ->retry(2, 250)
                ->get(rtrim((string) config('moyasar.api_url'), '/').'/payments/'.$paymentId);
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'payment' => [
                    'تعذر الاتصال بميسّر للتحقق من الدفع. أعد المحاولة.',
                ],
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'payment' => [
                    'تعذر التحقق من عملية الدفع لدى ميسّر.',
                ],
            ]);
        }

        return $response->json();
    }

    private function assertPaymentMatches(Order $order, array $payment): void
    {
        $status = (string) ($payment['status'] ?? '');
        $amount = (int) ($payment['amount'] ?? -1);
        $currency = strtoupper((string) ($payment['currency'] ?? ''));
        $paymentOrderId = (int) data_get($payment, 'metadata.order_id', 0);

        if ($status !== 'paid') {
            throw ValidationException::withMessages([
                'payment' => [$this->failureMessage($payment)],
            ]);
        }

        if (
            $amount !== $this->amountHalalas($order) ||
            $currency !== 'SAR' ||
            $paymentOrderId !== (int) $order->id
        ) {
            throw ValidationException::withMessages([
                'payment' => [
                    'بيانات الدفع لا تطابق مبلغ ورقم الطلب.',
                ],
            ]);
        }
    }

    private function markPaid(Order $order, array $payment): Order
    {
        return DB::transaction(function () use ($order, $payment): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->isPaid()) {
                return $order;
            }

            // ZAD_PAYMENT_LOCK_RECHECK_FINAL
            // Close the race between changing a coupon and a payment callback.
            $this->assertPaymentMatches($order, $payment);
            $this->assertV5LiveProof($order, $payment);

            $paymentId = (string) ($payment['id'] ?? '');
            $attempt = PaymentAttempt::query()
                ->where('order_id', $order->id)
                ->where(function ($query) use ($paymentId): void {
                    $query->where('provider_payment_id', $paymentId);

                    if (Str::isUuid($paymentId)) {
                        $query->orWhere('idempotency_key', $paymentId);
                    }
                })
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                $attempt = PaymentAttempt::query()->create([
                    'order_id' => $order->id,
                    'idempotency_key' => Str::isUuid($paymentId)
                        ? $paymentId
                        : (string) Str::uuid(),
                    'gateway' => $this->isV5PostpayOrder($order) ? 'moyasar_live'
                        : (str_starts_with($paymentId, 'local_') ? 'local_test' : 'moyasar'),
                    'amount_halalas' => $this->amountHalalas($order),
                    'currency' => 'SAR',
                ]);
            }

            $attempt->update([
                // Stamp proof provenance so V5 OTP checks a LIVE provider record.
                'gateway' => $this->isV5PostpayOrder($order) ? 'moyasar_live' : $attempt->gateway,
                'provider_payment_id' => $paymentId,
                'status' => 'paid',
                'payload' => $payment,
                'failure_message' => null,
                'paid_at' => now(),
                'failed_at' => null,
            ]);

            $provider = PaymentProvider::query()->firstOrCreate(
                ['code' => 'moyasar'],
                [
                    'name' => 'Moyasar',
                    'currency' => 'SAR',
                    'is_active' => true,
                    'settings' => ['status' => 'active'],
                ],
            );
            $grossAmount = round(((int) ($payment['amount'] ?? $this->amountHalalas($order))) / 100, 2);
            $providerFee = round(max(0, ((int) ($payment['fee'] ?? 0)) / 100), 2);
            $providerPaymentId = (string) ($payment['id'] ?? $attempt->idempotency_key);

            Payment::query()->updateOrCreate(
                ['provider_reference' => $providerPaymentId],
                [
                    'order_id' => $order->id,
                    'provider_id' => $provider->id,
                    'reference' => 'MYS-'.$order->id.'-'.Str::upper(substr(hash('sha256', $providerPaymentId), 0, 12)),
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
                        'attempt_id' => $attempt->id,
                        'fee_reported_by_provider' => array_key_exists('fee', $payment),
                    ],
                ],
            );

            $order->update(['payment_status' => Order::PAYMENT_PAID]);

            // ZAD_COUPON_CONSUME_FINAL
            \App\Models\OrderCouponUsage::query()
                ->where('order_id', $order->id)
                ->where('status', 'applied')
                ->update(['status' => 'consumed']);

            $this->launchAttribution->markOrderPaid($order);

            return $order->fresh(['items', 'store']);
        });
    }

    /** Only orders created by the new post-payment checkout need mode-specific proof. */
    private function isV5PostpayOrder(Order $order): bool
    {
        return trim((string) ($order->checkout_request_id ?? '')) !== '';
    }

    private function assertV5LiveCredentials(): void
    {
        abort_unless(
            MoyasarV5LivePaymentPolicy::hasLiveCredentials(
                (string) config('app.env'),
                (string) config('moyasar.publishable_key'),
                (string) config('moyasar.secret_key'),
            ),
            503,
            'بيئة الدفع الحقيقي غير مهيأة للطلبات الجديدة.',
        );
    }

    /** Call ONLY with a payment already fetched from Moyasar API for V5 orders. */
    private function assertV5LiveProof(Order $order, array $payment): void
    {
        if (! $this->isV5PostpayOrder($order)) {
            return;
        }
        $this->assertV5LiveCredentials();
        abort_unless(
            MoyasarV5LivePaymentPolicy::matchesProviderPayment(
                $payment,
                (int) $order->id,
                $this->amountHalalas($order),
            ),
            422,
            'تعذر إثبات دفع ميسر المباشر لهذا الطلب.',
        );
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $this->orderGuestAccess->assert($request, $order);
    }

    private function localTestEnabled(Order $order): bool
    {
        // ZAD_PRELAUNCH_TEST_PAYMENT_V4

        if (
            app()->environment(['local', 'testing'])
            && (bool) config(
                'moyasar.local_test_enabled',
                false,
            )
        ) {
            return true;
        }

        /*
         * Temporary production prelaunch test.
         *
         * Requires all internal testing switches.
         * No real money is charged.
         *
         * Disable these switches when Moyasar becomes live.
         */
        // ZAD_TEMP_PRODUCTION_TEST_PHONE_20261009
        // Production payment simulation disabled for security.
        if (app()->environment('production')) {
            return false;
        }

        return InternalTesting::simulatesPaymentFor(
            (string) $order->contact_phone,
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
            'data' => [
                'paid' => true,
                'order' => $this->orderSummary($order),
            ],
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
