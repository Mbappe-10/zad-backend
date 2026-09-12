<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DigitalEmployee;
use App\Models\DigitalTaskEvent;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DigitalEmployeeOrderTaskController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // قراءة الطلبات الحقيقية في هذه المرحلة لمالك المنصة النشط فقط.
        abort_unless(
            $user instanceof User
            && $user->isActive()
            && $user->isPlatformOwner(),
            403,
            'تحليل الطلبات متاح لمالك المنصة النشط فقط.'
        );

        $data = $request->validate([
            'digital_employee_id' => [
                'required',
                'integer',
                'min:1',
            ],
            'order_number' => [
                'required',
                'string',
                'max:100',
            ],
        ]);

        $orderNumber = trim($data['order_number']);

        abort_if(
            $orderNumber === '',
            422,
            'رقم الطلب مطلوب.'
        );

        $task = DB::transaction(function () use (
            $data,
            $orderNumber,
            $user
        ) {
            // قفل الموظف لتنسيق الإنشاء المتزامن عبر هذا المسار.
            $employee = DigitalEmployee::query()
                ->lockForUpdate()
                ->findOrFail($data['digital_employee_id']);

            abort_unless(
                (string) $employee->owner_id
                    === (string) $user->getKey(),
                403,
                'اختر موظفاً رقمياً تابعاً لحسابك.'
            );

            abort_unless(
                $employee->status === 'active',
                422,
                'الموظف الرقمي غير نشط.'
            );

            $dailyLimit = (int) $employee->max_daily_tasks;

            abort_unless(
                $dailyLimit > 0,
                422,
                'حدد حداً يومياً صالحاً لمهام الموظف الرقمي.'
            );

            $todayCount = $employee->tasks()
                ->whereDate('created_at', today())
                ->count();

            abort_if(
                $todayCount >= $dailyLimit,
                422,
                'تم بلوغ الحد اليومي للمهام.'
            );

            // البحث برقم الطلب الظاهر للمستخدم، وليس المعرف الداخلي.
            // نقرأ الحقول اللازمة للتحليل فقط.
            $orders = Order::query()
                ->select([
                    'id',
                    'number',
                    'status',
                    'payment_status',
                    'fulfillment_mode',
                    'driver_id',
                    'created_at',
                    'updated_at',
                    'accepted_at',
                    'preparing_at',
                    'ready_at',
                    'picked_up_at',
                    'delivered_at',
                    'cancelled_at',
                ])
                ->where('number', $orderNumber)
                ->limit(2)
                ->get();

            abort_if(
                $orders->isEmpty(),
                404,
                'لم يتم العثور على طلب بهذا الرقم.'
            );

            abort_if(
                $orders->count() > 1,
                409,
                'رقم الطلب مكرر في قاعدة البيانات؛ يلزم معالجة التكرار قبل التحليل.'
            );

            $order = $orders->first();
            $capturedAt = now()->toISOString();

            $statusLabels = [
                'pending' => 'بانتظار القبول',
                'accepted' => 'مقبول',
                'preparing' => 'قيد التجهيز',
                'ready' => 'جاهز',
                'assigned' => 'مسند',
                'picked_up' => 'تم الاستلام من المتجر',
                'delivering' => 'قيد التوصيل',
                'delivered' => 'تم التسليم',
                'completed' => 'مكتمل',
                'cancelled' => 'ملغى',
                'rejected' => 'مرفوض',
            ];

            $paymentLabels = [
                'unpaid' => 'غير مدفوع',
                'pending' => 'الدفع قيد الانتظار',
                'paid' => 'مدفوع',
                'failed' => 'فشل الدفع',
                'refunded' => 'تم رد المبلغ',
            ];

            $fulfillmentLabels = [
                'ready_now' => 'جاهز الآن',
                'live_preparation' => 'تجهيز مباشر',
            ];

            $snapshot = [
                'source' => 'database_order_snapshot',
                'captured_at' => $capturedAt,
                'order_id' => $order->getKey(),
                'order_number' => (string) $order->number,
                'status' => $order->status,
                'status_ar' => $statusLabels[$order->status]
                    ?? 'حالة غير معروفة',
                'payment_status' => $order->payment_status,
                'payment_status_ar' => $paymentLabels[$order->payment_status]
                    ?? 'حالة دفع غير معروفة',
                'fulfillment_mode' => $order->fulfillment_mode,
                'fulfillment_mode_ar' => $fulfillmentLabels[$order->fulfillment_mode]
                    ?? 'نوع تجهيز غير معلوم',
                'driver_assigned' => $order->driver_id !== null,
                'created_at' => $order->created_at?->toISOString(),
                'order_updated_at' => $order->updated_at?->toISOString(),
                'accepted_at' => $order->accepted_at?->toISOString(),
                'preparing_at' => $order->preparing_at?->toISOString(),
                'ready_at' => $order->ready_at?->toISOString(),
                'picked_up_at' => $order->picked_up_at?->toISOString(),
                'delivered_at' => $order->delivered_at?->toISOString(),
                'cancelled_at' => $order->cancelled_at?->toISOString(),

                // لا يوجد في النموذج المرسل موعد جاهزية متوقع.
                'expected_ready_at' => null,
                'delay_minutes' => null,
                'delay_assessment' => 'غير قابل للتحديد دون موعد متوقع أو سياسة تأخير معتمدة',
            ];

            $instructions = <<<'PROMPT'
حلل لقطة الطلب الموجودة في supplied_data فقط.
هذه بيانات مأخوذة من قاعدة البيانات وقت captured_at، وقد تتغير حالة الطلب بعدها.
اكتب الحالة والمقترح وما يحتاج اعتماداً، بالتنسيق الذي يطلبه النظام.
اذكر رقم الطلب وحالته، ووجود مندوب بحسب driver_assigned.
إذا كان driver_assigned يساوي true فلا تقترح إسناد مندوب جديد دون سبب مثبت.
ready_at وقت جاهزية مسجل وليس موعد جاهزية متوقعاً.
لا تحسب تأخيراً من الزمن المنقضي وحده؛ موعد الجاهزية المتوقع ومدة التأخير غير معلومين.
راع حالة الدفع وحالة الطلب عند اقتراح الخطوة التالية.
لا تقترح تجهيز أو توصيل طلب ملغى أو مرفوض أو مكتمل.
إذا تعارضت البيانات، اذكر الحاجة للتحقق بدلاً من اختراع تفسير.
كل مقترح تنفيذي يحتاج مراجعة المسؤول والتحقق من أحدث حالة للطلب قبل التنفيذ.
لا تقل إنك نفذت إرسالاً أو إسناداً أو تحديثاً.
PROMPT;

            $task = $employee->tasks()->create([
                'title' => 'مراجعة الطلب '.(string) $order->number,
                'instructions' => $instructions,
                'priority' => 'medium',
                'status' => 'queued',
                'input' => $snapshot,
            ]);

            DigitalTaskEvent::create([
                'digital_employee_task_id' => $task->id,
                'actor_id' => $user->getKey(),
                'event_type' => 'created',
                'to_status' => 'queued',
                'message' => 'تم إنشاء مهمة مراجعة من لقطة بيانات طلب دون تعديل الطلب.',
                'context' => [
                    'source' => 'database_order_snapshot',
                    'order_id' => $order->getKey(),
                    'captured_at' => $capturedAt,
                    'external_actions_executed' => false,
                ],
            ]);

            return $task;
        });

        return response()->json([
            'message' => 'تم إنشاء مهمة من بيانات الطلب. شغّل النموذج ثم راجع المسودة.',
            'data' => $task->fresh(),
        ], 201);
    }
}