<?php
namespace App\Http\Controllers\Api\Owner;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\PlatformAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class AppSettingsController extends Controller {
 public function index(): JsonResponse { return response()->json(['data'=>AppSetting::query()->orderBy('group')->orderBy('key')->get()->groupBy('group')]); }
 public function update(Request $r): JsonResponse {
  $d=$r->validate(['settings'=>'required|array|min:1','settings.*.key'=>'required|string|max:150','settings.*.value'=>'present','settings.*.group'=>'nullable|string|max:80','settings.*.is_public'=>'nullable|boolean','settings.*.description'=>'nullable|string|max:500']);
  DB::transaction(function()use($r,$d){ foreach($d['settings'] as $row){ $old=AppSetting::where('key',$row['key'])->first(); AppSetting::updateOrCreate(['key'=>$row['key']],['value'=>$row['value'],'group'=>$row['group']??$old?->group??'general','is_public'=>$row['is_public']??$old?->is_public??true,'description'=>$row['description']??$old?->description,'updated_by'=>$r->user()->id]); }
   if (class_exists(PlatformAuditLog::class)) PlatformAuditLog::create(['user_id'=>$r->user()->id,'resource'=>'app_settings','record_id'=>null,'action'=>'app.settings.updated','before'=>null,'after'=>['keys'=>collect($d['settings'])->pluck('key')->all()],'ip_address'=>$r->ip(),'user_agent'=>$r->userAgent()]);
  });
  return response()->json(['message'=>'تم تحديث إعدادات التطبيق.','data'=>AppSetting::whereIn('key',collect($d['settings'])->pluck('key'))->get()]);
 }
}
