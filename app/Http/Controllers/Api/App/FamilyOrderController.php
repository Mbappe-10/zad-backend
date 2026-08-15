<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Services\DeliveryOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FamilyOrderController extends Controller
{
    public function __construct(
        private readonly DeliveryOperationsService $delivery,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        $familyId = $this->familyId($request);

        $storeIds = Store::query()
            ->where('productive_family_id', $familyId)
            ->pluck('id');

        $orders = Order::query()->whereIn('store_id', $storeIds);

        return response()->json([
            'data' => [
                'new_orders_count' => (clone $orders)
                    ->where('status', Order::STATUS_PENDING)
                    ->count(),
                'active_orders_count' => (clone $orders)
                    ->whereIn('status', Order::runningStatuses())
                    ->count(),
                'orders_count' => (clone $orders)->count(),
                'products_count' => Product::query()
                    ->whereIn('store_id', $storeIds)
                    ->count(),
                'sales_total' => (float) (clone $orders)
                    ->where('payment_status', Order::PAYMENT_PAID)
                    ->whereIn('status', [
                        Order::STATUS_DELIVERED,
                        Order::STATUS_COMPLETED,
                    ])
                    ->sum('total'),
            ],
        ]);
    }

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
                Order::STATUS_ASSIGNED,
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

    public function transition(Request $request, Order $order): JsonResponse
    {
        $this->ensureBelongsToFamily($request, $order);

        $data = $request->validate([
            'status' => [
                'required',
                'in:accepted,preparing,ready,cancelled',
            ],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = $this->delivery->transition(
            $order,
            $data['status'],
            $data['note'] ?? null,
            $request->user()?->id,
        );

        $dispatchStatus = 'not_started';
        $dispatchMessage = null;

        if ($order->status === Order::STATUS_READY) {
            try {
                $this->delivery->autoAssign(
                    $order,
                    $request->user()?->id,
                );

                $order = $order->fresh();
                $dispatchStatus = 'assigned';
            } catch (ValidationException $exception) {
                $dispatchStatus = 'searching';
                $dispatchMessage = collect($exception->errors())
                    ->flatten()
                    ->first();
            }
        }

        $order->load([
            'items:id,order_id,product_id,product_name,quantity,unit_price,total,options',
            'store:id,productive_family_id,name_ar,name_en',
        ]);

        return response()->json([
            'message' => 'تم تحديث حالة الطلب.',
            'dispatch_status' => $dispatchStatus,
            'dispatch_message' => $dispatchMessage,
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

    private function ensureBelongsToFamily(
        Request $request,
        Order $order,
    ): void {
        $familyId = $this->familyId($request);

        $belongsToFamily = Store::query()
            ->whereKey($order->store_id)
            ->where('productive_family_id', $familyId)
            ->exists();

        abort_unless($belongsToFamily, 403);
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