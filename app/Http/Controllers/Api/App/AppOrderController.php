<?php
namespace App\Http\Controllers\Api\App;
use App\Http\Controllers\Controller;
use App\Models\{Order,OrderItem,PhoneVerification,Product,Store};
use App\Services\App\VehicleRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class AppOrderController extends Controller {
 public function __construct(private readonly VehicleRecommendationService $vehicles) {}
 public function store(Request $r): JsonResponse {
  $d=$r->validate(['guest_session_id'=>'required|string|max:36|exists:app_guest_sessions,id','verification_token'=>'required|string','store_id'=>'required|exists:stores,id','city_id'=>'nullable|exists:cities,id','distance_km'=>'required|numeric|min:0|max:500','latitude'=>'required|numeric|between:-90,90','longitude'=>'required|numeric|between:-180,180','address'=>'required|array','notes'=>'nullable|string|max:1000','items'=>'required|array|min:1','items.*.product_id'=>'required|exists:products,id','items.*.quantity'=>'required|integer|min:1|max:50','items.*.options'=>'nullable|array']);
  try{$token=decrypt($d['verification_token']);}catch(\Throwable){throw ValidationException::withMessages(['verification_token'=>'توثيق رقم الجوال غير صالح.']);}
  $verification=PhoneVerification::whereKey($token['id']??0)->whereNotNull('verified_at')->first(); if(!$verification || ($token['expires']??0)<now()->timestamp) throw ValidationException::withMessages(['verification_token'=>'انتهت صلاحية توثيق رقم الجوال.']);
  $store=Store::whereKey($d['store_id'])->where('status','approved')->firstOrFail(); $products=Product::whereIn('id',collect($d['items'])->pluck('product_id'))->where('store_id',$store->id)->where('status','published')->where('is_available',true)->get()->keyBy('id');
  if($products->count()!==collect($d['items'])->pluck('product_id')->unique()->count()) throw ValidationException::withMessages(['items'=>'بعض المنتجات غير متاحة أو ليست من المتجر المحدد.']);
  $sizes=['small'=>1,'medium'=>2,'large'=>3,'family'=>4]; $package='small'; $subtotal=0;
  foreach($d['items'] as $item){$p=$products[$item['product_id']];$subtotal+=(float)$p->price*(int)$item['quantity']; if(($sizes[$p->package_size??'small']??1)>($sizes[$package]??1))$package=$p->package_size;}
  $vehicle=$this->vehicles->recommend($package,(float)$d['distance_km']);
  $order=DB::transaction(function()use($d,$verification,$store,$products,$subtotal,$package,$vehicle){$o=Order::create(['number'=>'ZAD-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),'guest_session_id'=>$d['guest_session_id'],'contact_phone'=>$verification->phone,'store_id'=>$store->id,'city_id'=>$d['city_id']??$store->city_id,'status'=>'pending','payment_status'=>'unpaid','subtotal'=>$subtotal,'delivery_fee'=>0,'discount'=>0,'tax'=>0,'total'=>$subtotal,'delivery_address'=>$d['address'],'delivery_distance_km'=>$d['distance_km'],'delivery_latitude'=>$d['latitude'],'delivery_longitude'=>$d['longitude'],'notes'=>$d['notes']??null,'package_size'=>$package,'recommended_vehicle_type'=>$vehicle]); foreach($d['items'] as $item){$p=$products[$item['product_id']];OrderItem::create(['order_id'=>$o->id,'product_id'=>$p->id,'product_name'=>$p->name_ar,'quantity'=>$item['quantity'],'unit_price'=>$p->price,'total'=>(float)$p->price*(int)$item['quantity'],'options'=>$item['options']??null]);} return $o;});
  return response()->json(['message'=>'تم إنشاء الطلب.','data'=>$order->load('items'),'vehicle_recommendation'=>$vehicle],201);
 }
 public function show(Request $r, Order $order): JsonResponse { abort_unless(($r->header('X-Guest-Session') && $order->guest_session_id===$r->header('X-Guest-Session')) || ($r->user() && ($order->customer_id===$r->user()->appProfile?->customer_id)),403); return response()->json(['data'=>$order->load(['items','store','driver','history','journeyProofs'])]); }
}
