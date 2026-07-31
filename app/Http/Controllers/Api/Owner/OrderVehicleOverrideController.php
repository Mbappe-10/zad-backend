<?php
namespace App\Http\Controllers\Api\Owner;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\App\VehicleRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class OrderVehicleOverrideController extends Controller {
 public function __construct(private readonly VehicleRecommendationService $service){}
 public function update(Request $r, Order $order): JsonResponse { $this->service->assertOverrideAllowed(); $d=$r->validate(['vehicle_type'=>'required|in:scooter,motorcycle,car','reason'=>'required|string|max:1000']); $order->update(['assigned_vehicle_type'=>$d['vehicle_type'],'vehicle_rule_overridden'=>true,'vehicle_rule_overridden_by'=>$r->user()->id,'vehicle_override_reason'=>$d['reason']]); return response()->json(['message'=>'تم تجاوز توصية المركبة بواسطة المالك.','data'=>$order->fresh()]); }
}
