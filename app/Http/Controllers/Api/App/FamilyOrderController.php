<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Order;
use App\Models\OrderFeedbackItem;
use App\Models\OrderJourneyProof;
use App\Models\Product;
use App\Models\Store;
use App\Services\DeliveryOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            ->whereIn('store_id', $storeIds)
            ->where('payment_status', Order::PAYMENT_PAID);

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
            ->where('payment_status', Order::PAYMENT_PAID)
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
            ->where('payment_status', Order::PAYMENT_PAID)
            ->whereIn('status', [
                Order::STATUS_PENDING,
                Order::STATUS_ACCEPTED,
                Order::STATUS_PREPARING,
                Order::STATUS_READY,
                Order::STATUS_ASSIGNED,
                Order::STATUS_PICKED_UP,
                Order::STATUS_DELIVERING,
                Order::STATUS_DELIVERED,
                Order::STATUS_COMPLETED,
                Order::STATUS_CANCELLED,
                Order::STATUS_REJECTED,
            ])
            ->with([
                'items:id,order_id,product_id,product_name,quantity,unit_price,total,options',
                'store:id,productive_family_id,name_ar,name_en,pickup_address,pickup_latitude,pickup_longitude',
                'journeyProofs',
                'feedbackItems',
                'settlement',
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
        // ZAD_PAYMENT_FAMILY_GUARD
        abort_unless(
            $order->isPaid(),
            402,
            'لا يظهر الطلب للأسرة قبل اكتمال الدفع.',
        );

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
        // ZAD_PAYMENT_FAMILY_GUARD
        abort_unless(
            $order->isPaid(),
            402,
            'لا يظهر الطلب للأسرة قبل اكتمال الدفع.',
        );

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
            $data['status'] === Order::STATUS_READY &&
            ! $order->journeyProofs()
                ->where('stage', 'family_ready')
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'photo' => [
                    'يجب تصوير الطلب واعتماد جاهزيته قبل إسناده إلى المندوب.',
                ],
            ]);
        }

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

    /**
     * توثيق جاهزية الطلب بالصورة، ثم بدء الإسناد الآلي.
     */
    public function readyProof(Request $request, Order $order): JsonResponse
    {
        $this->ensureBelongsToFamily($request, $order);
        abort_unless($order->isPaid(), 402, 'يجب إتمام الدفع أولًا.');

        if (! in_array($order->status, [
            Order::STATUS_PENDING,
            Order::STATUS_ACCEPTED,
            Order::STATUS_PREPARING,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن توثيق الجاهزية في حالة الطلب الحالية.'],
            ]);
        }

        $data = $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $request->file('photo');
        $path = $file->store("orders/{$order->id}/journey", 'public');
        $existing = $order->journeyProofs()
            ->where('stage', 'family_ready')
            ->first();

        try {
            $order = DB::transaction(function () use (
                $request,
                $order,
                $data,
                $file,
                $path,
                $existing,
            ): Order {
                $realPath = $file->getRealPath();

                OrderJourneyProof::query()->updateOrCreate(
                    ['order_id' => $order->id, 'stage' => 'family_ready'],
                    [
                        'photo_path' => $path,
                        'latitude' => $data['latitude'] ?? null,
                        'longitude' => $data['longitude'] ?? null,
                        'note' => $data['note'] ?? 'اعتمدت الأسرة جاهزية الطلب بالصورة.',
                        'uploaded_by' => $request->user()?->id,
                        'photo_checksum' => $realPath !== false
                            ? hash_file('sha256', $realPath)
                            : null,
                        'photo_size_bytes' => $file->getSize(),
                        'photo_mime_type' => $file->getMimeType(),
                        'photo_purged_at' => null,
                    ],
                );

                $order->update(['fulfillment_mode' => Order::FULFILLMENT_READY_NOW]);

                return $this->delivery->transition(
                    $order,
                    Order::STATUS_READY,
                    $data['note'] ?? 'تم توثيق جاهزية الطلب بالصورة واعتماده للإسناد.',
                    $request->user()?->id,
                );
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }

        if (
            $existing !== null &&
            filled($existing->photo_path) &&
            $existing->photo_path !== $path
        ) {
            Storage::disk('public')->delete($existing->photo_path);
        }

        $dispatchStatus = 'searching';
        $dispatchMessage = 'تم توثيق الجاهزية، وجارٍ البحث عن أقرب مندوب.';

        try {
            $this->delivery->autoAssign($order, $request->user()?->id);
            $order = $order->fresh();
            $dispatchStatus = 'assigned';
            $dispatchMessage = 'تم توثيق الجاهزية وإسناد الطلب إلى مندوب مناسب.';
        } catch (ValidationException $exception) {
            $dispatchMessage = collect($exception->errors())->flatten()->first()
                ?? $dispatchMessage;
        }

        $this->loadOrderRelations($order);

        return response()->json([
            'message' => $dispatchMessage,
            'dispatch_status' => $dispatchStatus,
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
            'journeyProofs',
            'feedbackItems',
            'settlement',
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

            'ready_proof_uploaded' => $order->journeyProofs
                ->contains(fn (OrderJourneyProof $proof): bool =>
                    $proof->stage === 'family_ready' && filled($proof->photo_path)),

            'feedback_items' => $order->feedbackItems
                ->where('subject_type', 'family')
                ->where('visible_to_subject', true)
                ->sortByDesc('created_at')
                ->values()
                ->map(fn (OrderFeedbackItem $item): array => [
                    'id' => $item->id,
                    'category' => $item->category,
                    'details' => $item->details,
                    'status' => $item->status,
                    'created_at' => $item->created_at,
                ]),

            'settlement' => $order->settlement !== null
                ? [
                    'id' => $order->settlement->id,
                    'status' => $order->settlement->status,
                    'gross' => (float) $order->settlement->family_gross,
                    'commission' => (float) $order->settlement->family_commission,
                    'net' => (float) $order->settlement->family_net,
                    'release_due_at' => $order->settlement->release_due_at,
                    'released_at' => $order->settlement->released_at,
                    'hold_reason' => $order->settlement->hold_reason,
                ]
                : null,
        ];
    }
}
