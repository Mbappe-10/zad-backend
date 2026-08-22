<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderJourneyProof;
use App\Services\DeliveryOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DriverOrderController extends Controller
{
    public function __construct(
        private readonly DeliveryOperationsService $deliveryService,
    ) {
    }

    /**
     * المهام الحالية للمندوب.
     */
    public function index(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $orders = Order::query()
            ->where('driver_id', $driver->id)
            ->whereIn('status', [
                Order::STATUS_ASSIGNED,
                Order::STATUS_PICKED_UP,
                Order::STATUS_DELIVERING,
            ])
            ->with([
                'store:id,name_ar,name_en',
                'journeyProofs',
            ])
            ->latest('updated_at')
            ->get()
            ->map(
                fn (Order $order): array => $this->driverPayload($order),
            )
            ->values();

        return response()->json([
            'data' => $orders,
        ]);
    }

    /**
     * تفاصيل مهمة واحدة.
     */
    public function show(
        Request $request,
        Order $order,
    ): JsonResponse {
        $driver = $this->driver($request);

        $this->ensureOrderBelongsToDriver($order, $driver);

        $order->load([
            'store:id,name_ar,name_en',
            'journeyProofs',
        ]);

        return response()->json([
            'data' => $this->driverPayload($order),
        ]);
    }

    /**
     * تشغيل أو إيقاف استقبال المهام.
     */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_online' => ['required', 'boolean'],
        ]);

        $driver = $this->driver($request);

        $this->ensureDriverApproved($driver);

        $driver->update([
            'is_online' => (bool) $data['is_online'],
            'location_updated_at' => $data['is_online']
                ? now()
                : $driver->location_updated_at,
        ]);

        return response()->json([
            'message' => $driver->is_online
                ? 'أصبحت متاحًا لاستقبال المهام.'
                : 'تم إيقاف استقبال المهام مؤقتًا.',
            'data' => [
                'id' => $driver->id,
                'is_online' => (bool) $driver->is_online,
                'status' => $driver->status,
                'application_status' => $driver->application_status,
            ],
        ]);
    }

    /**
     * تحديث موقع المندوب من التطبيق.
     */
    public function location(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],
            'longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],
        ]);

        $driver = $this->driver($request);

        $this->ensureDriverApproved($driver);

        $driver->update([
            'current_latitude' => $data['latitude'],
            'current_longitude' => $data['longitude'],
            'location_updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'تم تحديث موقع المندوب.',
            'data' => [
                'latitude' => (float) $driver->current_latitude,
                'longitude' => (float) $driver->current_longitude,
                'location_updated_at' => $driver->location_updated_at,
            ],
        ]);
    }

    /**
     * استلام الطلب من الأسرة المنتجة مع صورة إثبات.
     */
    public function pickup(
        Request $request,
        Order $order,
    ): JsonResponse {
        $driver = $this->driver($request);

        $this->ensureDriverApproved($driver);
        $this->ensureOrderBelongsToDriver($order, $driver);
        $this->ensureOrderStatus(
            $order,
            [Order::STATUS_ASSIGNED],
            'لا يمكن استلام هذا الطلب في حالته الحالية.',
        );

        $data = $this->validateProofRequest($request);

        $this->storeProof(
            request: $request,
            order: $order,
            driver: $driver,
            stage: 'driver_pickup',
            data: $data,
        );

        $order = $this->deliveryService->transition(
            order: $order,
            status: Order::STATUS_PICKED_UP,
            note: $data['note'] ?? 'استلم المندوب الطلب.',
            userId: $request->user()->id,
        );

        return response()->json([
            'message' => 'تم توثيق استلام الطلب بنجاح.',
            'data' => $this->freshOrderPayload($order),
        ]);
    }

    /**
     * بدء رحلة التوصيل إلى العميل.
     */
    public function startDelivery(
        Request $request,
        Order $order,
    ): JsonResponse {
        $driver = $this->driver($request);

        $this->ensureDriverApproved($driver);
        $this->ensureOrderBelongsToDriver($order, $driver);
        $this->ensureOrderStatus(
            $order,
            [Order::STATUS_PICKED_UP],
            'يجب توثيق استلام الطلب قبل بدء التوصيل.',
        );

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = $this->deliveryService->transition(
            order: $order,
            status: Order::STATUS_DELIVERING,
            note: $data['note'] ?? 'بدأ المندوب رحلة التوصيل.',
            userId: $request->user()->id,
        );

        return response()->json([
            'message' => 'تم بدء رحلة التوصيل.',
            'data' => $this->freshOrderPayload($order),
        ]);
    }

    /**
     * تسليم الطلب للعميل مع صورة إثبات.
     */
    public function deliver(
        Request $request,
        Order $order,
    ): JsonResponse {
        $driver = $this->driver($request);

        $this->ensureDriverApproved($driver);
        $this->ensureOrderBelongsToDriver($order, $driver);
        $this->ensureOrderStatus(
            $order,
            [Order::STATUS_DELIVERING],
            'لا يمكن تسليم الطلب قبل بدء رحلة التوصيل.',
        );

        $data = $this->validateProofRequest($request);

        $this->storeProof(
            request: $request,
            order: $order,
            driver: $driver,
            stage: 'driver_delivery',
            data: $data,
        );

        $order = $this->deliveryService->transition(
            order: $order,
            status: Order::STATUS_DELIVERED,
            note: $data['note'] ?? 'سلّم المندوب الطلب للعميل.',
            userId: $request->user()->id,
        );

        return response()->json([
            'message' => 'تم توثيق تسليم الطلب بنجاح.',
            'data' => $this->freshOrderPayload($order),
        ]);
    }

    /**
     * جلب المندوب المرتبط بالمستخدم الحالي.
     */
    private function driver(Request $request): Driver
    {
        $profile = AppProfile::query()
            ->where('user_id', $request->user()->id)
            ->first();

        abort_unless(
            $profile !== null && $profile->driver_id !== null,
            403,
            'هذا الحساب غير مرتبط بمندوب.',
        );

        $driver = Driver::query()->find($profile->driver_id);

        abort_unless(
            $driver !== null,
            403,
            'تعذر العثور على بيانات المندوب.',
        );

        return $driver;
    }

    /**
     * التأكد من اعتماد الحساب وعدم إيقافه.
     */
    private function ensureDriverApproved(Driver $driver): void
    {
        $applicationApproved =
            $driver->application_status === Driver::APPLICATION_APPROVED;

        $operationallyApproved = in_array(
            $driver->status,
            ['active', 'approved'],
            true,
        );

        if (! $applicationApproved && ! $operationallyApproved) {
            throw ValidationException::withMessages([
                'driver' => [
                    'حساب المندوب غير معتمد لاستقبال وتنفيذ المهام.',
                ],
            ]);
        }

        if (in_array($driver->status, ['suspended', 'blocked'], true)) {
            throw ValidationException::withMessages([
                'driver' => [
                    'حساب المندوب موقوف مؤقتًا.',
                ],
            ]);
        }
    }

    /**
     * منع المندوب من فتح أو تنفيذ طلب مندوب آخر.
     */
    private function ensureOrderBelongsToDriver(
        Order $order,
        Driver $driver,
    ): void {
        abort_unless(
            (int) $order->driver_id === (int) $driver->id,
            403,
            'هذا الطلب غير مسند إلى حساب المندوب الحالي.',
        );
    }

    /**
     * التحقق من حالة الطلب قبل تنفيذ الإجراء.
     */
    private function ensureOrderStatus(
        Order $order,
        array $allowedStatuses,
        string $message,
    ): void {
        if (! in_array($order->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => [$message],
            ]);
        }
    }

    /**
     * قواعد صورة إثبات الاستلام أو التسليم.
     */
    private function validateProofRequest(Request $request): array
    {
        return $request->validate([
            'photo' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:8192',
            ],
            'latitude' => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],
            'longitude' => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],
            'note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);
    }

    /**
     * حفظ صورة إثبات الرحلة ومعلومات سلامتها.
     */
    private function storeProof(
        Request $request,
        Order $order,
        Driver $driver,
        string $stage,
        array $data,
    ): OrderJourneyProof {
        $file = $request->file('photo');

        $existingProof = OrderJourneyProof::query()
            ->where('order_id', $order->id)
            ->where('stage', $stage)
            ->first();

        $path = $file->store(
            "orders/{$order->id}/journey",
            'public',
        );

        if (
            $existingProof !== null &&
            $existingProof->photo_path !== null &&
            $existingProof->photo_path !== $path
        ) {
            Storage::disk('public')->delete(
                $existingProof->photo_path,
            );
        }

        $realPath = $file->getRealPath();

        return OrderJourneyProof::query()->updateOrCreate(
            [
                'order_id' => $order->id,
                'stage' => $stage,
            ],
            [
                'photo_path' => $path,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'note' => $data['note'] ?? null,
                'uploaded_by' => $request->user()->id,
                'photo_checksum' => $realPath !== false
                    ? hash_file('sha256', $realPath)
                    : null,
                'photo_size_bytes' => $file->getSize(),
                'photo_mime_type' => $file->getMimeType(),
                'photo_purged_at' => null,
            ],
        );
    }

    /**
     * إعادة تحميل الطلب بعد تنفيذ الإجراء.
     */
    private function freshOrderPayload(Order $order): array
    {
        $freshOrder = Order::query()
            ->with([
                'store:id,name_ar,name_en',
                'journeyProofs',
            ])
            ->findOrFail($order->id);

        return $this->driverPayload($freshOrder);
    }

    /**
     * البيانات المرسلة لتطبيق المندوب.
     */
    private function driverPayload(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,

            'store_id' => $order->store_id,
            'store' => $order->store,

            'package_size' => $order->package_size,
            'assigned_vehicle_type' => $order->assigned_vehicle_type,
            'recommended_vehicle_type' => $order->recommended_vehicle_type,

            'delivery_latitude' =>
                $order->delivery_latitude !== null
                    ? (float) $order->delivery_latitude
                    : null,

            'delivery_longitude' =>
                $order->delivery_longitude !== null
                    ? (float) $order->delivery_longitude
                    : null,

            'delivery_address' => $order->delivery_address,
            'contact_phone' => $order->contact_phone,
            'driver_notes' => $order->driver_notes,

            'delivery_fee' => $order->delivery_fee !== null
                ? (float) $order->delivery_fee
                : 0,

            'picked_up_at' => $order->picked_up_at,
            'delivered_at' => $order->delivered_at,
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,

            'proofs' => $order->journeyProofs
                ->map(fn (OrderJourneyProof $proof): array => [
                    'id' => $proof->id,
                    'stage' => $proof->stage,
                    'photo_url' => $proof->photo_path !== null
                        ? url(Storage::url($proof->photo_path))
                        : null,
                    'latitude' => $proof->latitude !== null
                        ? (float) $proof->latitude
                        : null,
                    'longitude' => $proof->longitude !== null
                        ? (float) $proof->longitude
                        : null,
                    'note' => $proof->note,
                    'created_at' => $proof->created_at,
                ])
                ->values(),
        ];
    }
}