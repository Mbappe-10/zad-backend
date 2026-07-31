<?php
namespace App\Http\Controllers\Api\App;
use App\Http\Controllers\Controller;
use App\Models\PhoneVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
class PhoneVerificationController extends Controller {
 public function send(Request $r): JsonResponse { $d=$r->validate(['phone'=>'required|string|max:20','purpose'=>'nullable|in:checkout,login,join','guest_session_id'=>'nullable|string|max:36']); $code=(string)random_int(100000,999999); PhoneVerification::where('phone',$d['phone'])->whereNull('verified_at')->delete(); $v=PhoneVerification::create(['phone'=>$d['phone'],'purpose'=>$d['purpose']??'checkout','code_hash'=>Hash::make($code),'expires_at'=>now()->addMinutes(5),'guest_session_id'=>$d['guest_session_id']??null]); $payload=['verification_id'=>$v->id,'expires_in_seconds'=>300,'channel'=>'sms','message'=>'تم إنشاء رمز التحقق.']; if(app()->environment(['local','testing'])) $payload['development_code']=$code; return response()->json($payload,201); }
 public function verify(Request $r): JsonResponse { $d=$r->validate(['verification_id'=>'required|integer','code'=>'required|digits:6']); $v=PhoneVerification::findOrFail($d['verification_id']); if($v->verified_at) return response()->json(['verified'=>true,'phone'=>$v->phone]); if(now()->greaterThan($v->expires_at) || $v->attempts>=5) throw ValidationException::withMessages(['code'=>'انتهت صلاحية رمز التحقق.']); $v->increment('attempts'); if(!Hash::check($d['code'],$v->code_hash)) throw ValidationException::withMessages(['code'=>'رمز التحقق غير صحيح.']); $v->update(['verified_at'=>now()]); return response()->json(['verified'=>true,'verification_token'=>encrypt(['id'=>$v->id,'phone'=>$v->phone,'expires'=>now()->addMinutes(20)->timestamp]),'phone'=>$v->phone]); }
}
