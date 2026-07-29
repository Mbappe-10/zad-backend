<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryPricingRule;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Vehicle;
use App\Services\DeliveryOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryOperationsController extends Controller
{
    public function __construct(private readonly DeliveryOperationsService $service) {}

    public function dashboard(): JsonResponse
    {
        return response()->json(['data' => [
            'orders' => [
                'pending' => Order::where('status', 'pending')->count(),
                'preparing' => Order::whereIn('status', ['accepted', 'preparing', 'ready'])->count(),
                'on_delivery' => Order::whereIn('status', ['picked_up', 'delivering'])->count(),
                'delivered_today' => Order::where('status', 'delivered')->whereDate('delivered_at', today())->count(),
                'cancelled_today' => Order::where('status', 'cancelled')->whereDate('cancelled_at', today())->count(),
            ],
            'drivers' => [
                'online' => Driver::where('is_online', true)->count(),
                'available' => Driver::where('is_online', true)->whereIn('status', ['active', 'approved'])->where('active_orders_count', 0)->count(),
                'busy' => Driver::where('active_orders_count', '>', 0)->count(),
            ],
        ]]);
    }

    public function availableDrivers(Request $request): JsonResponse
    {
        $data = $request->validate(['city_id' => 'nullable|exists:cities,id', 'vehicle_id' => 'nullable|exists:vehicles,id']);
        $drivers = Driver::query()->with('vehicle')->where('is_online', true)->whereIn('status', ['active', 'approved'])
            ->when($data['city_id'] ?? null, fn ($q, $id) => $q->where('city_id', $id))
            ->when($data['vehicle_id'] ?? null, fn ($q, $id) => $q->where('vehicle_id', $id))
            ->orderBy('active_orders_count')->orderByDesc('rating')->get();

        return response()->json(['data' => $drivers]);
    }

    public function updateDriverLocation(Request $request, Driver $driver): JsonResponse
    {
        $data = $request->validate(['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'is_online' => 'sometimes|boolean']);
        $driver->update(['current_latitude' => $data['latitude'], 'current_longitude' => $data['longitude'], 'location_updated_at' => now(), 'is_online' => $data['is_online'] ?? true]);

        return response()->json(['message' => 'تم تحديث موقع المندوب.', 'data' => $driver->fresh()]);
    }

    public function transition(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:accepted,preparing,ready,picked_up,delivering,delivered,cancelled', 'note' => 'nullable|string|max:1000']);
        $order = $this->service->transition($order, $data['status'], $data['note'] ?? null, $request->user()?->id);

        return response()->json(['message' => 'تم تحديث حالة الطلب.', 'data' => $order]);
    }

    public function assign(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['driver_id' => 'required|exists:drivers,id', 'force' => 'sometimes|boolean']);
        $assignment = $this->service->assign($order, Driver::findOrFail($data['driver_id']), $request->user()?->id, (bool) ($data['force'] ?? false));

        return response()->json(['message' => 'تم إسناد الطلب.', 'data' => $assignment], 201);
    }

    public function autoAssign(Request $request, Order $order): JsonResponse
    {
        $assignment = $this->service->autoAssign($order, $request->user()?->id);

        return response()->json(['message' => 'تم الإسناد الذكي لأفضل مندوب متاح.', 'data' => $assignment], 201);
    }

    public function timeline(Order $order): JsonResponse
    {
        return response()->json(['data' => OrderStatusHistory::where('order_id', $order->id)->with('changedBy:id,name')->latest()->get()]);
    }

    public function assignments(Order $order): JsonResponse
    {
        return response()->json(['data' => DeliveryAssignment::where('order_id', $order->id)->with('driver:id,name,phone,vehicle_type,plate_number')->latest()->get()]);
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate(['city_id' => 'nullable|exists:cities,id', 'vehicle_id' => 'nullable|exists:vehicles,id', 'distance_km' => 'required|numeric|min:0|max:500']);
        $rule = DeliveryPricingRule::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('city_id')->orWhere('city_id', $data['city_id'] ?? null))
            ->where(fn ($q) => $q->whereNull('vehicle_id')->orWhere('vehicle_id', $data['vehicle_id'] ?? null))
            ->orderBy('priority')->first();
        $vehicle = isset($data['vehicle_id']) ? Vehicle::find($data['vehicle_id']) : null;
        $base = (float) ($rule?->base_fee ?? $vehicle?->base_fee ?? 0);
        $perKm = (float) ($rule?->per_km_fee ?? $vehicle?->per_km_fee ?? 0);
        $minimum = (float) ($rule?->minimum_fee ?? 0);
        $multiplier = (float) ($rule?->surge_multiplier ?? 1);
        $fee = max($minimum, ($base + $perKm * (float) $data['distance_km']) * $multiplier);

        return response()->json(['data' => ['delivery_fee' => round($fee,2), 'distance_km' => (float) $data['distance_km'], 'rule_id' => $rule?->id, 'vehicle_id' => $vehicle?->id]]);
    }
}
