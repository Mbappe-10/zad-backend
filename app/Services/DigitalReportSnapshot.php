<?php

namespace App\Services;

use App\Models\Order;
use Carbon\CarbonImmutable as Clock;

class DigitalReportSnapshot
{
    public function build(object $assignment, object $run): array
    {
        $captured = Clock::now('UTC');
        // Full calendar days preceding the scheduled date, in the assignment timezone.
        $to = Clock::parse($run->scheduled_at, 'UTC')->setTimezone($assignment->timezone)->startOfDay();
        $from = $to->subDays((int) $assignment->period_days);
        $databaseTimezone = config('app.timezone', 'UTC');
        // One aggregate SELECT supplies every metric, avoiding mismatched counts across queries.
        $row = Order::query()
            ->where('created_at', '>=', $from->setTimezone($databaseTimezone)->format('Y-m-d H:i:s'))
            ->where('created_at', '<', $to->setTimezone($databaseTimezone)->format('Y-m-d H:i:s'))
            ->selectRaw("COUNT(*) AS orders_count,
                COALESCE(SUM(CASE WHEN status IN ('delivered','completed') THEN 1 ELSE 0 END),0) AS completed_count,
                COALESCE(SUM(CASE WHEN status IN ('cancelled','rejected') THEN 1 ELSE 0 END),0) AS cancelled_count,
                COALESCE(SUM(CASE WHEN status IN ('pending','accepted','preparing','ready','assigned','picked_up','delivering') THEN 1 ELSE 0 END),0) AS running_count,
                COALESCE(SUM(CASE WHEN status IN ('pending','accepted','preparing','ready','assigned','picked_up','delivering') AND driver_id IS NULL THEN 1 ELSE 0 END),0) AS unassigned_count,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END),0) AS paid_count,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' AND status NOT IN ('cancelled','rejected') THEN total ELSE 0 END),0) AS paid_order_value,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' AND status IN ('cancelled','rejected') THEN total ELSE 0 END),0) AS paid_cancelled_value,
                COALESCE(SUM(CASE WHEN payment_status = 'refunded' THEN 1 ELSE 0 END),0) AS refunded_count")
            ->first();
        $metrics = [
            ['الطلبات المنشأة خلال الفترة', (int) $row->orders_count, 'طلب'],
            ['منها مسلّمة أو مكتملة وقت استخراج التقرير', (int) $row->completed_count, 'طلب'],
            ['منها ملغاة أو مرفوضة وقت الاستخراج', (int) $row->cancelled_count, 'طلب'],
            ['منها قيد التشغيل وقت الاستخراج', (int) $row->running_count, 'طلب'],
            ['من الطلبات قيد التشغيل: دون مندوب مسند', (int) $row->unassigned_count, 'طلب'],
            ['عدد الطلبات المسجلة بحالة دفع مدفوع', (int) $row->paid_count, 'طلب'],
            ['قيمة الطلبات المدفوعة غير الملغاة وغير المرفوضة', (string) $row->paid_order_value, 'ر.س'],
            ['قيمة الطلبات المدفوعة الملغاة أو المرفوضة', (string) $row->paid_cancelled_value, 'ر.س'],
            ['الطلبات المسجلة بحالة دفع مسترد', (int) $row->refunded_count, 'طلب'],
        ];
        if ($assignment->report_type === 'sales') {
            $metrics = [...array_slice($metrics, 5), ...array_slice($metrics, 0, 5)];
        }
        return [
            'version' => 1,
            'title' => $assignment->title,
            'report_type' => $assignment->report_type,
            'source' => 'Laravel / orders',
            'captured_at' => $captured->toIso8601String(),
            'from' => $from->toIso8601String(),
            'to_exclusive' => $to->toIso8601String(),
            'timezone' => $assignment->timezone,
            'metrics' => $metrics,
            'notes' => [
                'الفترة حسب تاريخ إنشاء الطلب؛ الحالات والدفع كما هما وقت الاستخراج، وليسا إعادة بناء للحالة التاريخية.',
                'قيمة الطلبات ليست إيراد المنصة أو صافي الربح أو إثبات التحصيل البنكي. لم تُحسب عمولات أو تسويات أو ضرائب محاسبية.',
                'تستبعد الطلبات المحذوفة حذفاً ناعماً وفق نموذج Order الحالي.',
                'غياب المندوب لا يعني تأخر الطلب. لا يتوفر موعد متوقع معتمد لحساب التأخير.',
                'الأرقام محسوبة مباشرة من قاعدة البيانات. هذا تقرير آلي بقالب ثابت؛ لم يُستخدم النموذج لتخمين الأرقام أو إجراء حسابات.',
                'اعتماد التقرير يسجل المراجعة فقط؛ لا ينفذ إسناداً أو رسالة أو حركة مالية.',
            ],
        ];
    }
}
