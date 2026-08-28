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
use Illuminate\Support\Facades\DB;
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

        $orders = Order::query()
            ->whereIn('store_id', $storeIds);

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
                Order::STATUS_PICKED_UP,
                Order::STATUS_DELIVERING,
            ])
            ->with([
                'items:id,order_id,product_id,product_name,quantity,unit_price,total,options',
                'store:id,productive_family_id,name_ar,name_en,pickup_address,pickup_latitude,pickup_longitude',
            ])
            ->latest()
            ->get()
            ->map(
                fn (Order $order): array => $this->familyPayload($order),
            )
            ->values();

        return response()->json([
            'data' => $orders,
        ]);
    }

    public function show(
        Request $request,
        Order $order,
    ): JsonResponse {
        $this->ensureBelongsToFamily($request, $order);

        $this->loadOrderRelations($order);

        return response()->json([
            'data' => $this->familyPayload($order),
        ]);
    }

    public function transition(
        Request $request,
        Order $order,
    ): JsonResponse {
        $this->ensureBelongsToFamily($request, $order);

        $data = $request->validate([
            'status' => [
                'required',
                'string',
                'in:accepted,preparing,ready,cancelled',
            ],
            'fulfillment_mode' => [
                'nullable',
                'string',
                'in:ready_now,live_preparation',
            ],
            'note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        if (
            $order->status === Order::STATUS_PENDING &&
            in_array($data['status'], [
                Order::STATUS_ACCEPTED,
                Order::STATUS_PREPARING,
                Order::STATUS_READY,
            ], true)
        ) {
            $store = Store::query()->find($order->store_id);

            if (
                $store === null ||
                $store->pickup_latitude === null ||
                $store->pickup_longitude === null
            ) {
                throw ValidationException::withMessages([
                    'pickup_location' => [
                        'حدد موقع استلام الطلبات من صفحة بيانات الأسرة والمتجر أولًا، ثم اقبل الطلب.',
                    ],
                ]);
            }
        }

        $order = DB::transaction(function () use (
            $order,
            $data,
            $request,
        ): Order {
            if (
                array_key_exists('fulfillment_mode', $data) &&
                $data['fulfillment_mode'] !== null
            ) {
                $order->update([
                    'fulfillment_mode' => $data['fulfillment_mode'],
                ]);
            }

            return $this->delivery->transition(
                $order,
                $data['status'],
                $data['note'] ?? null,
                $request->user()?->id,
            );
        });

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
                $dispatchMessage = 'تم إسناد الطلب إلى مندوب مناسب.';
            } catch (ValidationException $exception) {
                $dispatchStatus = 'searching';
                $dispatchMessage = collect($exception->errors())
                    ->flatten()
                    ->first();
            }
        }

        $this->loadOrderRelations($order);

        return response()->json([
            'message' => 'تم تحديث حالة الطلب.',
            'dispatch_status' => $dispatchStatus,
            'dispatch_message' => $dispatchMessage,
            'data' => $this->familyPayload($order),
        ]);
    }

    private function familyId(Request $request): int
    {
        $user = $request->user();

        abort_unless(
            $user !== null,
            401,
            'يجب تسجيل الدخول أولًا.',
        );

        $profile = AppProfile::query()
            ->where('user_id', $user->id)
            ->first();

        abort_unless(
            $profile !== null &&
            $profile->productive_family_id !== null,
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

        abort_unless(
            $belongsToFamily,
            403,
            'هذا الطلب لا يتبع الأسرة المنتجة الحالية.',
        );
    }

    private function loadOrderRelations(Order $order): void
    {
        $order->load([
            'items:id,order_id,product_id,product_name,quantity,unit_price,total,options',
            'store:id,productive_family_id,name_ar,name_en,pickup_address,pickup_latitude,pickup_longitude',
        ]);
    }

    private function familyPayload(Order $order): array
    {
        $chatAvailable = $order->driver_id !== null && in_array(
            $order->status,
            [
                Order::STATUS_ASSIGNED,
                Order::STATUS_PICKED_UP,
                Order::STATUS_DELIVERING,
            ],
            true,
        );

        return [
            'id' => $order->id,
            'number' => $order->number,
            'store_id' => $order->store_id,

            'status' => $order->status,
            'fulfillment_mode' => $order->fulfillment_mode,
            'driver_id' => $order->driver_id,
            'chat_available' => $chatAvailable,

            'payment_status' => $order->payment_status,
            'subtotal' => (float) $order->subtotal,
            'total' => (float) $order->total,
            'package_size' => $order->package_size,

            'notes' => $order->notes,

            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,

            'store' => $order->store !== null
                ? [
                    'id' => $order->store->id,
                    'name_ar' => $order->store->name_ar,
                    'name_en' => $order->store->name_en,
                    'pickup_address' => $order->store->pickup_address,
                    'pickup_latitude' =>
                        $order->store->pickup_latitude !== null
                            ? (float) $order->store->pickup_latitude
                            : null,
                    'pickup_longitude' =>
                        $order->store->pickup_longitude !== null
                            ? (float) $order->store->pickup_longitude
                            : null,
                ]
                : null,
            'items' => $order->items,
        ];
    }
}
