<?php
namespace App\Http\Controllers\Api\App;
use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\AppSetting;
use App\Models\ApprovalRequest;
use App\Models\Driver;
use App\Models\ProductiveFamily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class JoinController extends Controller {
 public function family(Request $r): JsonResponse {
  abort_unless((bool)AppSetting::where('key','registration.family_enabled')->value('value'),403,'التسجيل متوقف مؤقتًا.');
  $d=$r->validate(['owner_name'=>'required|string|max:100','phone'=>'required|string|max:20|unique:productive_families,phone','email'=>'nullable|email|max:150|unique:productive_families,email','city_id'=>'required|exists:cities,id','health_certificate_number'=>'nullable|string|max:100','health_certificate_expires_at'=>'nullable|date']);
  $family=DB::transaction(function()use($r,$d){
   $family=ProductiveFamily::create(array_merge($d,['code'=>'FAM-'.Str::upper(Str::random(8)),'status'=>'pending']));
   $p=AppProfile::firstOrCreate(['user_id'=>$r->user()->id],['roles'=>['customer']]); $roles=array_values(array_unique(array_merge($p->roles??['customer'],['family_pending']))); $p->update(['productive_family_id'=>$family->id,'roles'=>$roles]);
   ApprovalRequest::create(['type'=>'productive_family_join','approvable_type'=>ProductiveFamily::class,'approvable_id'=>$family->id,'requested_by'=>$r->user()->id,'status'=>'pending','payload'=>$d]);
   return $family;
  });
  return response()->json(['message'=>'تم إرسال طلب الانضمام كأسرة منتجة.','data'=>$family],201);
 }
 public function driver(Request $r): JsonResponse {
  abort_unless((bool)AppSetting::where('key','registration.driver_enabled')->value('value'),403,'التسجيل متوقف مؤقتًا.');
  $d=$r->validate(['name'=>'required|string|max:100','phone'=>'required|string|max:20|unique:drivers,phone','city_id'=>'required|exists:cities,id','identity_number'=>'required|string|max:30|unique:drivers,identity_number','license_number'=>'required|string|max:50','vehicle_type'=>'required|string|max:50','plate_number'=>'required|string|max:30']);
  $driver=DB::transaction(function()use($r,$d){
   $driver=Driver::create(array_merge($d,['user_id'=>$r->user()->id,'code'=>'DRV-'.Str::upper(Str::random(8)),'status'=>'pending']));
   $p=AppProfile::firstOrCreate(['user_id'=>$r->user()->id],['roles'=>['customer']]); $roles=array_values(array_unique(array_merge($p->roles??['customer'],['driver_pending']))); $p->update(['driver_id'=>$driver->id,'roles'=>$roles]);
   ApprovalRequest::create(['type'=>'driver_join','approvable_type'=>Driver::class,'approvable_id'=>$driver->id,'requested_by'=>$r->user()->id,'status'=>'pending','payload'=>$d]);
   return $driver;
  });
  return response()->json(['message'=>'تم إرسال طلب الانضمام كمندوب.','data'=>$driver],201);
 }
}
