<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\PaymentAttempt;
use App\Services\App\OrderGuestAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Cancel only a V5 checkout that has not started a provider payment. */
final class UnpaidOrderCancellationController extends Controller
{
    public function __construct(private readonly OrderGuestAccess $access) {}

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
                $payload = $attempt->payload;
                $untouched = $payload === null || $payload === [] || $payload === '';
                abort_unless(
                    $attempt->gateway === 'moyasar_live'
                    && $attempt->status === 'created'
                    && blank($attempt->provider_payment_id)
                    && $untouched,
                    409,
                    'بدأت محاولة دفع أو تعذر التأكد من حالتها. لا يمكن الإلغاء قبل مراجعة ميسر.',
                );
            }

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
