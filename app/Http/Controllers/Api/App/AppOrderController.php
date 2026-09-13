<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\DeliveryPricingRule;
use App\Models\Order;
use App\Models\OrderFeedbackItem;
use App\Models\OrderItem;
use App\Models\OrderJourneyProof;
use App\Models\OrderRating;
use App\Models\OrderStatusHistory;
use App\Models\PhoneVerification;
use App\Models\Product;
use App\Models\Store;
use App\Services\App\VehicleRecommendationService;
use App\Services\VehiclePricingService;
use App\Services\OrderSettlementService;
use App\Services\DeliveryVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppOrderController extends Controller
{
    public function __construct(
        private readonly VehicleRecommendationService $vehicles,
        private readonly VehiclePricingService $pricing,
        private readonly OrderSettlementService $settlements,
        private readonly DeliveryVerificationService $deliveryVerification,
    ) {
    }

    // ZAD_DELIVERY_OTP_V1

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'guest_session_id' => ['required', 'string', 'max:36', 'exists:app_guest_sessions,id'],
            'verification_token' => ['required', 'string'],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'distance_km' => ['required', 'numeric', 'min:0', 'max:500'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'address' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'driver_notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.options' => ['nullable', 'array'],
        ]);

        $verification = $this->verifiedPhone(
            $data['verification_token'],
            $data['guest_session_id'],
        );

        $store = Store::query()
            ->whereKey($data['store_id'])
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('is_open', true)
            ->first();

        if ($store === null) {
            throw ValidationException::withMessages([
                'store_id' => ['المتجر غير متاح لاستقبال الطلبات حاليًا.'],
            ]);
        }

        // ZAD_PAYMENT_DISTANCE_INSTALLED: delivery distance is authoritative on Laravel.
        $distanceKm = $this->distanceKm(
            $store->pickup_latitude !== null ? (float) $store->pickup_latitude : null,
            $store->pickup_longitude !== null ? (float) $store->pickup_longitude : null,
            (float) $data['latitude'],
            (float) $data['longitude'],
            (float) $data['distance_km'],
        );
        $requestedProductIds = collect($data['items'])
            ->pluck('product_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $products = Product::query()
            ->whereIn('id', $requestedProductIds)
            ->where('store_id', $store->id)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('is_available', true)
            ->get()
            ->keyBy('id');

        if ($products->count() !== $requestedProductIds->count()) {
            throw ValidationException::withMessages([
                'items' => ['بعض المنتجات غير متاحة أو ليست من المتجر المحدد.'],
            ]);
        }

        $sizes = [
            'small' => 1,
            'medium' => 2,
            'large' => 3,
            'family' => 4,
        ];

        $packageSize = 'small';
        $subtotal = 0.0;
        $fulfillmentMode = Order::FULFILLMENT_READY_NOW;

        foreach ($data['items'] as $item) {
            $product = $products[(int) $item['product_id']];
            $quantity = (int) $item['quantity'];
            $preparationMode = $this->productPreparationMode($product);

            $subtotal += (float) $product->price * $quantity;

            if ($preparationMode === Product::PREPARATION_MADE_TO_ORDER) {
                $fulfillmentMode = Order::FULFILLMENT_LIVE_PREPARATION;
            }

            $productPackageSize = $product->package_size ?? 'small';

            if (
                ($sizes[$productPackageSize] ?? 1) >
                ($sizes[$packageSize] ?? 1)
            ) {
                $packageSize = $productPackageSize;
            }
        }

        $vehicleRecommendation = $this->vehicles->recommend(
            $packageSize,
            $distanceKm,
        );

        $cityId = $data['city_id'] ?? $store->city_id;
        // ZAD_VEHICLE_PRICING_SERVICE
        $deliveryFee = $this->pricing->quote(
            $cityId !== null ? (int) $cityId : null,
            $vehicleRecommendation,
            $distanceKm,
        );

        $order = DB::transaction(function () use (
            $data,
            $verification,
            $store,
            $products,
            $subtotal,
            $packageSize,
            $vehicleRecommendation,
            $fulfillmentMode,
            $deliveryFee,
            $distanceKm,
        ): Order {
            $order = Order::query()->create([
                'number' => $this->generateOrderNumber(),
                'customer_id' => null,
                'guest_session_id' => $data['guest_session_id'],
                'contact_phone' => $verification->phone,
                'store_id' => $store->id,
                'city_id' => $data['city_id'] ?? $store->city_id,
                'status' => Order::STATUS_PENDING,
                'payment_status' => Order::PAYMENT_UNPAID,
                'fulfillment_mode' => $fulfillmentMode,
                'subtotal' => round($subtotal, 2),
                'delivery_fee' => $deliveryFee,
                'discount' => 0,
                'tax' => 0,
                'total' => round($subtotal + $deliveryFee, 2),
                'delivery_address' => $data['address'],
                'delivery_distance_km' => $distanceKm,
                'delivery_latitude' => (float) $data['latitude'],
                'delivery_longitude' => (float) $data['longitude'],
                'pickup_address' => $store->pickup_address,
                'pickup_latitude' => $store->pickup_latitude,
                'pickup_longitude' => $store->pickup_longitude,
                'notes' => $data['notes'] ?? null,
                'driver_notes' => $data['driver_notes'] ?? null,
                'package_size' => $packageSize,
                'recommended_vehicle_type' => $vehicleRecommendation,
            ]);

            foreach ($data['items'] as $item) {
                $product = $products[(int) $item['product_id']];
                $quantity = (int) $item['quantity'];
                $unitPrice = (float) $product->price;

                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name_ar,
                    'preparation_mode' => $this->productPreparationMode($product),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total' => round($unitPrice * $quantity, 2),
                    'options' => $item['options'] ?? null,
                ]);
            }

            return $order;
        });

        return response()->json([
            'message' => 'تم إنشاء الطلب بنجاح.',
            'data' => $order->load(['items', 'store']),
            'vehicle_recommendation' => $vehicleRecommendation,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $guestSessionId = trim((string) $request->header('X-Guest-Session', ''));

        if ($guestSessionId === '') {
            return response()->json(['data' => []]);
        }

        $orders = Order::query()
            ->where('guest_session_id', $guestSessionId)
            ->latest()
            ->with(['items', 'store', 'driver', 'settlement'])
            ->get();

        return response()->json(['data' => $orders]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $guestSessionId = trim((string) $request->header('X-Guest-Session', ''));

        $allowedByGuest =
            $guestSessionId !== '' &&
            $order->guest_session_id === $guestSessionId;

        $allowedByCustomer =
            $request->user() !== null &&
            $order->customer_id !== null &&
            $order->customer_id ===
                $request->user()->appProfile?->customer_id;

        abort_unless($allowedByGuest || $allowedByCustomer, 403);
        abort_unless(
            $order->isPaid(),
            402,
            'يجب إتمام الدفع قبل فتح تتبع الطلب.',
        );

        $order->load([
            'items',
            'store',
            'driver',
            'history',
            'settlement',
            'deliveryVerification',
            'rating',
            'journeyProofs',
        ]);

        $payload = $order->toArray();
        $payload['delivery_receipt_available'] =
            $order->status === Order::STATUS_DELIVERING ||
            $order->deliveryVerification !== null;
        $payload['delivery_feedback_submitted'] = $order->rating !== null;

        // ZAD_CUSTOMER_TRACKING_PROOFS_V1
        $familyReadyProof = $order->journeyProofs
            ->first(fn (OrderJourneyProof $proof): bool =>
                $proof->stage === 'family_ready' && filled($proof->photo_path));

        $deliveryVerified =
            $order->deliveryVerification?->verified_at !== null &&
            in_array($order->status, [
                Order::STATUS_DELIVERED,
                Order::STATUS_COMPLETED,
            ], true);

        $deliveryProof = $deliveryVerified
            ? ($order->journeyProofs
                ->first(fn (OrderJourneyProof $proof): bool =>
                    $proof->stage === 'driver_delivery' && filled($proof->photo_path))
                ?? $order->journeyProofs
                    ->first(fn (OrderJourneyProof $proof): bool =>
                        $proof->stage === 'customer_arrival' && filled($proof->photo_path)))
            : null;

        $payload['tracking_proofs'] = collect([
            $familyReadyProof !== null
                ? $this->customerTrackingProof(
                    $familyReadyProof,
                    'family_ready',
                    'اعتماد جاهزية الطلب',
                    'هذا هو طلبك بعد التجهيز والتغليف من الأسرة المنتجة.',
                )
                : null,
            $deliveryProof !== null
                ? $this->customerTrackingProof(
                    $deliveryProof,
                    'customer_delivery',
                    'تم تسليم الطلب لك',
                    'اكتمل التسليم بعد التحقق من رمز الاستلام الخاص بطلبك.',
                )
                : null,
        ])->filter()->values()->all();

        return response()->json(['data' => $payload]);
    }

    /**
     * @return array<string, mixed>
     */
    private function customerTrackingProof(
        OrderJourneyProof $proof,
        string $stage,
        string $title,
        string $description,
    ): array {
        return [
            'id' => $proof->id,
            'stage' => $stage,
            'title' => $title,
            'description' => $description,
            // ZAD_TRACKING_MEDIA_STREAM_V1
            'photo_url' => URL::temporarySignedRoute(
                'api.app.order-tracking.proof-media',
                now()->addHours(12),
                ['proof' => $proof->id],
                absolute: false,
            ),
            'documented_at' => $proof->created_at,
        ];
    }

    /**
     * Serve customer tracking media through Laravel instead of relying on
     * the Windows public/storage link.
     */
    public function trackingProofMedia(
        OrderJourneyProof $proof,
    ): StreamedResponse {
        abort_if(
            blank($proof->photo_path) ||
                ! Storage::disk('public')->exists($proof->photo_path),
            404,
        );

        return Storage::disk('public')->response(
            $proof->photo_path,
            null,
            [
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function deliveryReceipt(Request $request, Order $order): JsonResponse
    {
        $this->ensureCustomerOwnsOrder($request, $order);
        abort_unless($order->isPaid(), 402, 'يجب إتمام الدفع أولًا.');
        abort_unless(
            in_array($order->status, [
                Order::STATUS_DELIVERING,
                Order::STATUS_DELIVERED,
                Order::STATUS_COMPLETED,
            ], true),
            422,
            'رمز التسليم يظهر عندما يبدأ المندوب رحلة التوصيل.',
        );

        $payload = $this->deliveryVerification->customerPayload($order);
        $payload['rating_submitted'] = $order->rating()->exists();

        return response()->json(['data' => $payload]);
    }

    public function rateDelivery(Request $request, Order $order): JsonResponse
    {
        $this->ensureCustomerOwnsOrder($request, $order);
        abort_unless(
            in_array($order->status, Order::completedStatuses(), true),
            422,
            'لا يمكن إرسال التقييم قبل إثبات استلام الطلب.',
        );
        abort_unless(
            $order->deliveryVerification?->verified_at !== null,
            422,
            'يجب التحقق من رمز التسليم أولًا.',
        );

        $data = $request->validate([
            'arrival_condition' => ['required', 'in:perfect,good,issue'],
            'driver_score' => ['required', 'integer', 'between:1,5'],
            'driver_tags' => ['nullable', 'array', 'max:4'],
            'driver_tags.*' => ['string', 'in:on_time,protected_order,polite,smooth_delivery'],
            'food_quality_score' => ['required', 'integer', 'between:1,5'],
            'cleanliness_packaging_score' => ['required', 'integer', 'between:1,5'],
            'order_accuracy_score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:500'],
            'feedback_items' => ['nullable', 'array', 'max:6'],
            'feedback_items.*.subject_type' => ['required', 'string', 'in:driver,family,order'],
            'feedback_items.*.category' => [
                'required',
                'string',
                'in:arrival_condition,driver_service,food_quality,cleanliness_packaging,order_accuracy,other',
            ],
            'feedback_items.*.details' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $rating = DB::transaction(function () use ($request, $order, $data): OrderRating {
            $rating = OrderRating::query()->updateOrCreate(
                ['order_id' => $order->id],
                [
                    'customer_id' => $request->user()?->appProfile?->customer_id,
                    'guest_session_id' => $order->guest_session_id,
                    'driver_id' => $order->driver_id,
                    'store_id' => $order->store_id,
                    'arrival_condition' => $data['arrival_condition'],
                    'driver_score' => $data['driver_score'],
                    'driver_tags' => $data['driver_tags'] ?? [],
                    'food_quality_score' => $data['food_quality_score'],
                    'cleanliness_packaging_score' => $data['cleanliness_packaging_score'],
                    'order_accuracy_score' => $data['order_accuracy_score'],
                    'comment' => $data['comment'] ?? null,
                    'submitted_at' => now(),
                ],
            );

            if ($order->driver_id !== null) {
                $driverAverage = OrderRating::query()
                    ->where('driver_id', $order->driver_id)
                    ->avg('driver_score');
                DB::table('drivers')->where('id', $order->driver_id)->update([
                    'rating' => round((float) $driverAverage, 2),
                    'updated_at' => now(),
                ]);
            }

            $storeAverage = OrderRating::query()
                ->where('store_id', $order->store_id)
                ->get()
                ->avg(fn (OrderRating $item): float => (
                    $item->food_quality_score
                    + $item->cleanliness_packaging_score
                    + $item->order_accuracy_score
                ) / 3);
            DB::table('stores')->where('id', $order->store_id)->update([
                'rating' => round((float) $storeAverage, 2),
                'updated_at' => now(),
            ]);

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'from_status' => $order->status,
                'to_status' => $order->status,
                'note' => 'أرسل العميل تقييم المندوب والأسرة المنتجة.',
                'changed_by' => $request->user()?->id,
            ]);

            OrderFeedbackItem::query()
                ->where('order_id', $order->id)
                ->where('order_rating_id', $rating->id)
                ->delete();

            foreach ($data['feedback_items'] ?? [] as $item) {
                $subjectId = match ($item['subject_type']) {
                    'driver' => $order->driver_id,
                    'family' => $order->store?->productive_family_id,
                    default => $order->id,
                };

                OrderFeedbackItem::query()->create([
                    'order_id' => $order->id,
                    'order_rating_id' => $rating->id,
                    'subject_type' => $item['subject_type'],
                    'subject_id' => $subjectId,
                    'category' => $item['category'],
                    'details' => trim($item['details']),
                    'status' => 'new',
                    'visible_to_subject' => true,
                ]);
            }

            return $rating;
        });

        return response()->json([
            'message' => 'شكرًا لك، تم حفظ تقييمك.',
            'data' => $rating,
        ]);
    }
    public function confirmDelivery(Request $request, Order $order): JsonResponse
    {
        $guestSessionId = trim((string) $request->header('X-Guest-Session', ''));

        $allowedByGuest =
            $guestSessionId !== '' &&
            $order->guest_session_id === $guestSessionId;

        $allowedByCustomer =
            $request->user() !== null &&
            $order->customer_id !== null &&
            $order->customer_id === $request->user()->appProfile?->customer_id;

        abort_unless($allowedByGuest || $allowedByCustomer, 403);
        abort_unless(
            in_array($order->status, Order::completedStatuses(), true),
            422,
            'لا يمكن تأكيد الاستلام قبل أن يسلم المندوب الطلب.',
        );

        $settlement = $order->settlement
            ?? $this->settlements->prepare($order, $request->user()?->id);

        abort_if(
            $settlement->status === 'held',
            422,
            'التسوية موقوفة بقرار من الإدارة ولا يمكن تحريرها قبل المراجعة.',
        );

        return response()->json([
            'message' => 'تم تأكيد استلام الطلب، وسيتم تحرير المستحقات آليًا حسب دورة التسوية.',
            'data' => [
                'order' => $order->fresh(),
                'settlement' => $settlement,
            ],
        ]);
    }

    private function ensureCustomerOwnsOrder(Request $request, Order $order): void
    {
        $guestSessionId = trim((string) $request->header('X-Guest-Session', ''));
        $allowedByGuest =
            $guestSessionId !== '' &&
            $order->guest_session_id === $guestSessionId;
        $allowedByCustomer =
            $request->user() !== null &&
            $order->customer_id !== null &&
            $order->customer_id === $request->user()->appProfile?->customer_id;

        abort_unless($allowedByGuest || $allowedByCustomer, 403);
    }
    private function distanceKm(
        ?float $pickupLatitude,
        ?float $pickupLongitude,
        float $deliveryLatitude,
        float $deliveryLongitude,
        float $fallback,
    ): float {
        if ($pickupLatitude === null || $pickupLongitude === null) {
            return round(max($fallback, 0), 2);
        }

        $earthRadiusKm = 6371.0088;
        $latitudeDelta = deg2rad($deliveryLatitude - $pickupLatitude);
        $longitudeDelta = deg2rad($deliveryLongitude - $pickupLongitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($pickupLatitude))
            * cos(deg2rad($deliveryLatitude))
            * sin($longitudeDelta / 2) ** 2;

        return round($earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }
    private function deliveryFee(?int $cityId, float $distanceKm): float
    {
        $rule = DeliveryPricingRule::query()
            ->where('is_active', true)
            ->whereNull('vehicle_id')
            ->where(fn ($query) => $query
                ->whereNull('city_id')
                ->orWhere('city_id', $cityId))
            ->orderBy('priority')
            ->first();

        $cityBaseFee = $cityId !== null
            ? (float) (City::query()->whereKey($cityId)->value('delivery_base_fee') ?? 0)
            : 0;

        $base = (float) ($rule?->base_fee ?? $cityBaseFee);
        $perKm = (float) ($rule?->per_km_fee ?? 0);
        $minimum = (float) ($rule?->minimum_fee ?? 0);
        $multiplier = max((float) ($rule?->surge_multiplier ?? 1), 0);

        return round(
            max($minimum, ($base + ($perKm * max($distanceKm, 0))) * $multiplier),
            2,
        );
    }

    private function productPreparationMode(Product $product): string
    {
        return in_array(
            $product->preparation_mode,
            Product::preparationModes(),
            true,
        )
            ? $product->preparation_mode
            : Product::PREPARATION_MADE_TO_ORDER;
    }

    private function verifiedPhone(
        string $verificationToken,
        string $guestSessionId,
    ): PhoneVerification {
        try {
            $token = decrypt($verificationToken);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'verification_token' => ['توثيق رقم الجوال غير صالح.'],
            ]);
        }

        $verification = PhoneVerification::query()
            ->whereKey($token['id'] ?? 0)
            ->whereNotNull('verified_at')
            ->first();

        if (
            $verification === null ||
            ($token['expires'] ?? 0) < now()->timestamp
        ) {
            throw ValidationException::withMessages([
                'verification_token' => ['انتهت صلاحية توثيق رقم الجوال.'],
            ]);
        }

        if (
            isset($token['phone']) &&
            (string) $token['phone'] !== (string) $verification->phone
        ) {
            throw ValidationException::withMessages([
                'verification_token' => ['بيانات توثيق رقم الجوال غير متطابقة.'],
            ]);
        }

        if (($token['purpose'] ?? null) !== 'checkout') {
            throw ValidationException::withMessages([
                'verification_token' => [
                    'رمز التحقق غير مخصص لإنشاء طلب.',
                ],
            ]);
        }

        if (
            isset($token['guest_session_id'])
            && (string) $token['guest_session_id'] !== $guestSessionId
        ) {
            throw ValidationException::withMessages([
                'verification_token' => [
                    'رمز التحقق لا يخص جلسة هذا الجهاز.',
                ],
            ]);
        }

        if (
            $verification->guest_session_id !== null &&
            $verification->guest_session_id !== $guestSessionId
        ) {
            throw ValidationException::withMessages([
                'verification_token' => ['توثيق رقم الجوال لا يخص جلسة هذا الجهاز.'],
            ]);
        }

        return $verification;
    }

    private function generateOrderNumber(): string
    {
        do {
            $number =
                'ZADSYNC-'.
                now()->format('ymd').
                '-'.
                Str::upper(Str::random(6));
        } while (
            Order::withTrashed()
                ->where('number', $number)
                ->exists()
        );

        return $number;
    }
}
