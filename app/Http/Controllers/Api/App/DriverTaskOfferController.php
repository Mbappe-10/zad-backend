<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Driver;
use App\Models\Order;
use App\Services\DeliveryOperationsService;
use App\Services\DriverTaskOfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverTaskOfferController extends Controller
{
    public function __construct(private readonly DriverTaskOfferService $offers,
        private readonly DeliveryOperationsService $delivery) {}

    private function driver(Request $request): Driver
    {
        $profile = AppProfile::query()->where('user_id', $request->user()->id)->first();
        abort_unless($profile && $profile->driver_id, 403, 'الحساب ليس حساب مندوب.');
        return Driver::query()->findOrFail($profile->driver_id);
    }

    public function index(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        return response()->json([
            'data' => $this->offers->listFor($driver),
            'enabled' => DriverTaskOfferService::enabled(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function accept(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['offer_expires_at' => ['required', 'date']]);
        $driver = $this->driver($request);
        $result = $this->offers->accept($order, $driver, $this->delivery,
            (int) $request->user()->id, (string) $data['offer_expires_at']);
        return response()->json(['message' => 'تم قبول المهمة بنجاح.', 'data' => $result]);
    }

    public function decline(Request $request, Order $order): JsonResponse
    {
        $this->offers->decline($order, $this->driver($request));
        return response()->json(['message' => 'تم رفض المهمة ولن تظهر لهذا المندوب مرة أخرى.']);
    }
}
