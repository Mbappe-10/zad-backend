<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $driverId = $this->driverId($request);

        $orders = Order::query()
            ->where('driver_id', $driverId)
            ->whereIn('status', [
                Order::STATUS_ASSIGNED,
                Order::STATUS_PICKED_UP,
                Order::STATUS_DELIVERING,
            ])
            ->with('store:id,name_ar,name_en')
            ->latest()
            ->get()
            ->map(fn (Order $order): array => $this->driverPayload($order))
            ->values();

        return response()->json(['data' => $orders]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $driverId = $this->driverId($request);

        abort_unless((int) $order->driver_id === $driverId, 403);

        $order->load('store:id,name_ar,name_en');

        return response()->json([
            'data' => $this->driverPayload($order),
        ]);
    }

    private function driverId(Request $request): int
    {
        $profile = AppProfile::query()
            ->where('user_id', $request->user()->id)
            ->first();

        abort_unless(
            $profile !== null && $profile->driver_id !== null,
            403,
            'هذا الحساب غير مرتبط بمندوب.',
        );

        return (int) $profile->driver_id;
    }

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
            'delivery_latitude' => $order->delivery_latitude !== null
                ? (float) $order->delivery_latitude
                : null,
            'delivery_longitude' => $order->delivery_longitude !== null
                ? (float) $order->delivery_longitude
                : null,
            'delivery_address' => $order->delivery_address,
            'driver_notes' => $order->driver_notes,
            'created_at' => $order->created_at,
        ];
    }
}