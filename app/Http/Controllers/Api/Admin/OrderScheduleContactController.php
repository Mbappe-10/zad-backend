<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
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
        abort_unless(DB::table('order_schedules')->where('order_id', $order->id)->exists(), 404);
        $data = $request->validate(['kind' => 'required|in:call,note', 'note' => 'required|string|max:1000']);
        $id = DB::table('order_schedule_contacts')->insertGetId($data + [
            'user_id' => $request->user()->id, 'order_id' => $order->id, 'created_at' => now(),
        ]);

        return response()->json(['id' => $id], 201);
    }
}
