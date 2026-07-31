<?php
namespace App\Http\Controllers\Api\App;
use App\Http\Controllers\Controller;
use App\Models\{AppSetting,Order,OrderJourneyProof};
use App\Services\DeliveryOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
class OrderJourneyController extends Controller {
 public function __construct(private readonly DeliveryOperationsService $delivery){}
 public function store(Request $r, Order $order): JsonResponse { $d=$r->validate(['stage'=>'required|in:prepared,picked_up,delivered','photo'=>'required|image|max:8192','latitude'=>'nullable|numeric|between:-90,90','longitude'=>'nullable|numeric|between:-180,180','note'=>'nullable|string|max:1000']); $expected=['prepared'=>['accepted','preparing','ready'],'picked_up'=>['ready','picked_up'],'delivered'=>['picked_up','delivering','delivered']]; if(!in_array($order->status,$expected[$d['stage']],true)) throw ValidationException::withMessages(['stage'=>'هذه الصورة لا تناسب المرحلة الحالية للطلب.']); $path=$r->file('photo')->store("order-journey/{$order->id}",'public'); $proof=OrderJourneyProof::updateOrCreate(['order_id'=>$order->id,'stage'=>$d['stage']],['photo_path'=>$path,'latitude'=>$d['latitude']??null,'longitude'=>$d['longitude']??null,'note'=>$d['note']??null,'uploaded_by'=>$r->user()?->id]); $target=match($d['stage']){'prepared'=>'ready','picked_up'=>'picked_up','delivered'=>'delivered'}; if($order->status!==$target){ if($d['stage']==='prepared' && $order->status==='accepted') $order=$this->delivery->transition($order,'preparing','بدأ تجهيز الطلب',$r->user()?->id); if($order->status!==$target)$order=$this->delivery->transition($order,$target,$d['note']??'تم توثيق المرحلة بالصورة',$r->user()?->id); } return response()->json(['message'=>'تم توثيق مرحلة الطلب وإرسال تحديث للعميل.','data'=>$proof,'order'=>$order->fresh()]); }
 public function index(Order $order): JsonResponse { return response()->json(['data'=>$order->journeyProofs()->orderBy('created_at')->get()]); }
}
