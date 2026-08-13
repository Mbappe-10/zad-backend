<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FamilyOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $familyId = $this->familyId($request);

        $storeIds = Store::query()
            ->where('productive_family_id', $familyId)
            ->pluck('id');

        $orders = Order::query()
            ->whereIn('store_id', $storeIds)
            ->whereIn('status', [
                Order::STATUS_PENDING,
                Order::STATUS_ACCEPTED,
                Order::STATUS_PREPARING,
                Order::STATUS_READY,
            ])
            ->with([
                'items:id,order_id,product_id,product_name,quantity,unit_price,total,options',
                'store:id,productive_family_id,name_ar,name_en',
            ])
            ->latest()
            ->get()
            ->map(fn (Order $order): array => $this->familyPayload($order))
            ->values();

        return response()->json(['data' => $orders]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $familyId = $this->familyId($request);

        $belongsToFamily = Store::query()
            ->whereKey($order->store_id)
            ->where('productive_family_id', $familyId)
            ->exists();

        abort_unless($belongsToFamily, 403);

        $order->load([
            'items:id,order_id,product_id,product_name,quantity,unit_price,total,options',
            'store:id,productive_family_id,name_ar,name_en',
        ]);

        return response()->json([
            'data' => $this->familyPayload($order),
        ]);
    }

    private function familyId(Request $request): int
    {
        $profile = AppProfile::query()
            ->where('user_id', $request->user()->id)
            ->first();

        abort_unless(
            $profile !== null && $profile->productive_family_id !== null,
            403,
            'هذا الحساب غير مرتبط بأسرة منتجة.',
        );

        return (int) $profile->productive_family_id;
    }

    private function familyPayload(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'store_id' => $order->store_id,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'subtotal' => (float) $order->subtotal,
            'total' => (float) $order->total,
            'package_size' => $order->package_size,
            'notes' => $order->notes,
            'created_at' => $order->created_at,
            'store' => $order->store,
            'items' => $order->items,
        ];
    }
}