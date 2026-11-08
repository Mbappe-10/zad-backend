<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\PaymentAttempt;
use App\Services\App\OrderGuestAccess;
use App\Services\App\MoyasarModeResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Cancel only a V5 checkout that has not started a provider payment. */
final class UnpaidOrderCancellationController extends Controller
{
    public function __construct(private readonly OrderGuestAccess $access, private readonly MoyasarModeResolver $modes) {}

    public function cancel(Request $request, Order $order): JsonResponse
    {
        // A guest UUID by itself is not sufficient. Require the issued guest token.
        $this->access->assert($request, $order);

        return DB::transaction(function () use ($request, $order): JsonResponse {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $this->access->assert($request, $locked);

            abort_unless(filled($locked->checkout_request_id), 409,
                'إلغاء الطلب من هذه الصفحة متاح للطلبات الجديدة فقط.');

            if ($locked->status === Order::STATUS_CANCELLED) {
                return response()->json([
                    'message' => 'الطلب ملغي مسبقًا.',
                    'data' => ['id' => $locked->id, 'status' => Order::STATUS_CANCELLED],
                ]);
            }

            abort_unless(
                $locked->status === Order::STATUS_PENDING && $locked->driver_id === null,
                409, 'لا يمكن إلغاء طلب بدأ تنفيذه.',
            );
            abort_unless(
                in_array($locked->payment_status, [Order::PAYMENT_UNPAID, Order::PAYMENT_PENDING], true),
                409, 'حالة الدفع لا تسمح بالإلغاء المباشر. تحقق من الدفع أولًا.',
            );
            abort_if(DB::table('payments')->where('order_id', $locked->id)->exists(),
                409, 'توجد عملية مالية مرتبطة بالطلب. يلزم مراجعتها قبل الإلغاء.');

            // The Web checkout prepares a local attempt when the summary loads.
            // A locally CREATED attempt with no provider interaction may be
            // cancelled. Once an intent exists, a payment is processing, or
            // the gateway state is ambiguous, fail closed and reconcile first.
            $attempts = PaymentAttempt::query()
                ->where('order_id', $locked->id)
                ->lockForUpdate()
                ->get();

            foreach ($attempts as $attempt) {
                abort_unless(
                    $attempt->gateway === 'moyasar_live'
                    && $attempt->status === 'created'
                    && blank($attempt->provider_payment_id),
                    409,
                    'حالة محاولة الدفع لا تسمح بإلغاء الطلب.',
                );

                $payload = is_array($attempt->payload)
                    ? $attempt->payload : [];

                $state = (string) ($payload['web_intent_state'] ?? '');
                $intentId = trim((string) ($payload['web_intent_id'] ?? ''));

                // A creation in progress may have reached Moyasar already.
                abort_if($state === 'creating', 409,
                    'جلسة الدفع قيد الإنشاء. يرجى المحاولة لاحقًا.');

                if ($intentId === '') {
                    abort_unless($state === '', 409,
                        'حالة جلسة الدفع غير مؤكدة.');
                    continue;
                }

                abort_unless(
                    $state === 'ready'
                    && \Illuminate\Support\Str::isUuid($intentId),
                    409,
                    'جلسة الدفع تحتاج مراجعة قبل الإلغاء.',
                );

                // Keep the order row locked during the remote cancellation.
                // This blocks a concurrent new web-intent reservation.
                $credentials = $this->modes->credentials('live');
                $secretKey = $credentials['secret_key'];
                $url = 'https://api.moyasar.com/v1/payment_intents/'
                    .rawurlencode($intentId);

                try {
                    $check = Http::acceptJson()
                        ->withBasicAuth($secretKey, '')
                        ->timeout(10)
                        ->get($url);

                    abort_unless($check->successful(), 503,
                        'تعذر التحقق من جلسة الدفع لدى ميسر.');

                    $remote = $check->json();

                    abort_unless(
                        is_array($remote)
                        && (string) ($remote['id'] ?? '') === $intentId,
                        503,
                        'بيانات جلسة الدفع غير متطابقة.',
                    );

                    $remoteStatus = (string) ($remote['status'] ?? '');

                    if ($remoteStatus !== 'canceled') {
                        abort_unless($remoteStatus === 'created', 409,
                            'بدأ الدفع أو تغيرت حالته. لا يمكن الإلغاء.');

                        $cancel = Http::acceptJson()
                            ->withBasicAuth($secretKey, '')
                            ->timeout(10)
                            ->post($url.'/cancel');

                        abort_unless($cancel->successful(), 409,
                            'لم يؤكد ميسر إبطال جلسة الدفع.');

                        $result = $cancel->json();

                        abort_unless(
                            is_array($result)
                            && (string) ($result['id'] ?? '') === $intentId
                            && (string) ($result['status'] ?? '') === 'canceled',
                            409,
                            'لم يتم تأكيد إلغاء جلسة الدفع.',
                        );
                    }
                } catch (ConnectionException) {
                    abort(503,
                        'تعذر الاتصال بميسر. لم يتم إلغاء الطلب.');
                }

                // Preserve the attempt as an audit record.
                // Mark only the provider session as canceled.
                $attempt->update([
                    'payload' => array_merge($payload, [
                        'web_intent_state' => 'canceled',
                    ]),
                ]);
            }

            // Recheck the financial state before changing the order.
            $locked->refresh();

            abort_unless(
                $locked->status === Order::STATUS_PENDING
                && in_array($locked->payment_status, [
                    Order::PAYMENT_UNPAID,
                    Order::PAYMENT_PENDING,
                ], true)
                && $locked->driver_id === null,
                409,
                'تغيرت حالة الطلب. لا يمكن الإلغاء.',
            );

            abort_if(
                DB::table('payments')
                    ->where('order_id', $locked->id)
                    ->exists(),
                409,
                'توجد عملية مالية مرتبطة بالطلب.',
            );
            $locked->update([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);
            OrderStatusHistory::query()->create([
                'order_id' => $locked->id,
                'from_status' => Order::STATUS_PENDING,
                'to_status' => Order::STATUS_CANCELLED,
                'note' => 'ألغى العميل طلبًا غير مدفوع قبل بدء عملية الدفع لدى ميسر.',
                'changed_by' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'تم إلغاء الطلب غير المدفوع. يمكنك إنشاء طلب جديد.',
                'data' => ['id' => $locked->id, 'status' => Order::STATUS_CANCELLED],
            ]);
        }, 3);
    }
}
