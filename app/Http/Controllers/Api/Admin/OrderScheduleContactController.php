<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderScheduleContactController extends Controller
{
    public function index(Order $order): JsonResponse
    {
        $entries = DB::table('order_schedule_contacts as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.order_id', $order->id)->orderByDesc('c.id')
            ->select('c.id', 'c.kind', 'c.note', 'c.created_at', 'u.name as employee_name')
            ->paginate(25);

        return response()->json($entries);
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $schedule = DB::table('order_schedules')->where('order_id', $order->id)->first();
        abort_unless($schedule !== null, 404);

        $data = $request->validate([
            'kind' => 'required|in:call,note,remind_family',
            'note' => 'required|string|max:1000',
        ]);

        $id = DB::table('order_schedule_contacts')->insertGetId([
            'kind' => $data['kind'] === 'remind_family' ? 'note' : $data['kind'],
            'note' => $data['note'],
            'user_id' => $request->user()->id,
            'order_id' => $order->id,
            'created_at' => now(),
        ]);

        if ($data['kind'] === 'remind_family') {
            $familyUserIds = DB::table('stores')
                ->join('app_profiles as p', 'p.productive_family_id', '=', 'stores.productive_family_id')
                ->where('stores.id', $order->store_id)
                ->pluck('p.user_id');

            $now = now('UTC');
            foreach ($familyUserIds as $uid) {
                DB::table('in_app_notifications')->insert([
                    'user_id' => $uid,
                    'title' => 'تنبيه عاجل من إدارة زاد — طلب مجدول',
                    'body' => "تذكير بخصوص الطلب المجدول {$order->number}: {$data['note']}",
                    'action_url' => '/family/orders',
                    'data' => json_encode(['order_id' => $order->id, 'source' => 'admin_reminder'], JSON_UNESCAPED_UNICODE),
                    'idempotency_key' => hash('sha256', "admin-remind|{$order->id}|{$uid}|" . microtime(true)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        return response()->json(['id' => $id], 201);
    }

    public function updateSchedule(Request $request, Order $order): JsonResponse
    {
        $schedule = DB::table('order_schedules')->where('order_id', $order->id)->first();
        abort_unless($schedule !== null, 404);

        $data = $request->validate([
            'delivery_at' => 'nullable|date',
            'family_status' => 'nullable|in:pending,confirmed,declined',
            'decline_reason' => 'nullable|string|max:500',
            'occasion' => 'nullable|string|max:120',
            'guest_count' => 'nullable|integer|min:1|max:5000',
        ]);

        $updates = ['updated_at' => now('UTC')];
        if (! empty($data['delivery_at'])) {
            $updates['delivery_at'] = Carbon::parse($data['delivery_at'])->utc();
        }
        if (! empty($data['family_status'])) {
            $updates['family_status'] = $data['family_status'];
            $updates['family_responded_at'] = now('UTC');
            if ($data['family_status'] !== 'declined') {
                $updates['decline_reason'] = null;
            }
        }
        if (array_key_exists('decline_reason', $data)) {
            $updates['decline_reason'] = $data['decline_reason'];
        }
        if (! empty($data['occasion'])) {
            $updates['occasion'] = $data['occasion'];
        }
        if (array_key_exists('guest_count', $data)) {
            $updates['guest_count'] = $data['guest_count'];
        }

        DB::table('order_schedules')->where('order_id', $order->id)->update($updates);

        DB::table('order_schedule_contacts')->insert([
            'user_id' => $request->user()->id,
            'order_id' => $order->id,
            'kind' => 'note',
            'note' => 'تم تحديث بيانات الجدولة إدارياً من لوحة التحكم.',
            'created_at' => now(),
        ]);

        return response()->json([
            'data' => DB::table('order_schedules')->where('order_id', $order->id)->first(),
        ]);
    }
}
