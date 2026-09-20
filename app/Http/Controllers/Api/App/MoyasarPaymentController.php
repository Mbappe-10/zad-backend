<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentAttempt;
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

        abort_if(
            $order->isCancelled(),
            422,
            'لا يمكن دفع طلب ملغي أو مرفوض.',
        );

        $publishableKey = trim((string) config('moyasar.publishable_key'));
        $localTestEnabled = $this->localTestEnabled($order);

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
            ->where('gateway', 'moyasar')
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
                'gateway' => 'moyasar',
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

        $payment = $this->fetchPayment($data['payment_id']);
        $this->assertPaymentMatches($order, $payment);
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
            $this->assertPaymentMatches($order, $payment);
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
                    'gateway' => str_starts_with($paymentId, 'local_') ? 'local_test' : 'moyasar',
                    'amount_halalas' => $this->amountHalalas($order),
                    'currency' => 'SAR',
                ]);
            }

            $attempt->update([
                'provider_payment_id' => $paymentId,
                'status' => 'paid',
                'payload' => $payment,
                'failure_message' => null,
                'paid_at' => now(),
                'failed_at' => null,
            ]);

            $order->update(['payment_status' => Order::PAYMENT_PAID]);

            return $order->fresh(['items', 'store']);
        });
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
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

    private function localTestEnabled(Order $order): bool
    {
        if (
            app()->environment(['local', 'testing'])
            && (bool) config('moyasar.local_test_enabled', false)
        ) {
            return true;
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
