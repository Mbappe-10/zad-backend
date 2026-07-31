<?php
namespace App\Http\Controllers\Api\App;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Category;
use App\Models\City;
use Illuminate\Http\JsonResponse;
class AppBootstrapController extends Controller {
 public function __invoke(): JsonResponse { $settings=AppSetting::query()->where('is_public',true)->get()->mapWithKeys(fn($s)=>[$s->key=>$s->value]); return response()->json(['data'=>['entry'=>['mode'=>'guest','initial_screen'=>'home','login_required'=>false,'phone_verification_trigger'=>'checkout'],'permission_prompts'=>['location'=>(bool)($settings['permissions.ask_location_on_first_open']??true),'notifications'=>(bool)($settings['permissions.ask_notifications_on_first_open']??true)],'settings'=>$settings,'cities'=>City::query()->where('is_active',true)->orderBy('name_ar')->get(['id','code','name_ar','name_en','delivery_base_fee']),'categories'=>Category::query()->where('is_active',true)->orderBy('sort_order')->get(['id','parent_id','name_ar','name_en','slug','image_path'])]]); }
}
