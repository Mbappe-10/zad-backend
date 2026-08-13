<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PhoneVerification;
use App\Models\Product;
use App\Models\Store;
use App\Services\App\VehicleRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AppOrderController extends Controller
{
    public function __construct(
        private readonly VehicleRecommendationService $vehicles,
    ) {
    }

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

        foreach ($data['items'] as $item) {
            $product = $products[(int) $item['product_id']];
            $quantity = (int) $item['quantity'];

            $subtotal += (float) $product->price * $quantity;

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
            (float) $data['distance_km'],
        );

        $order = DB::transaction(function () use (
            $data,
            $verification,
            $store,
            $products,
            $subtotal,
            $packageSize,
            $vehicleRecommendation,
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
                'subtotal' => round($subtotal, 2),
                'delivery_fee' => 0,
                'discount' => 0,
                'tax' => 0,
                'total' => round($subtotal, 2),
                'delivery_address' => $data['address'],
                'delivery_distance_km' => (float) $data['distance_km'],
                'delivery_latitude' => (float) $data['latitude'],
                'delivery_longitude' => (float) $data['longitude'],
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
            ->with(['items', 'store', 'driver'])
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

        return response()->json([
            'data' => $order->load(['items', 'store', 'driver', 'history']),
        ]);
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
                'ZAD-'.
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