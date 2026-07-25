<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\PlatformAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class ApprovalRequestController extends Controller {
 public function index(Request $request): JsonResponse {
  $query=ApprovalRequest::query()->latest();
  if($request->filled('status')) $query->where('status',$request->string('status'));
  if($request->filled('type')) $query->where('type',$request->string('type'));
  return response()->json($query->paginate(min(max((int)$request->input('per_page',25),1),100)));
 }
 public function store(Request $request): JsonResponse {
  $data=$request->validate(['type'=>['required','string','max:100'],'approvable_type'=>['nullable','string','max:190'],'approvable_id'=>['nullable','integer'],'assigned_to'=>['nullable','exists:users,id'],'payload'=>['nullable','array'],'reason'=>['nullable','string','max:2000']]);
  $approval=ApprovalRequest::create([...$data,'requested_by'=>$request->user()->id,'status'=>'pending']);
  return response()->json(['message'=>'تم إرسال طلب الاعتماد.','data'=>$approval],201);
 }
 public function decide(Request $request, ApprovalRequest $approvalRequest): JsonResponse {
  abort_unless($approvalRequest->status==='pending',422,'تم اتخاذ قرار على هذا الطلب مسبقًا.');
  $data=$request->validate(['decision'=>['required','in:approved,rejected'],'reason'=>['nullable','string','max:2000']]);
  DB::transaction(function() use($request,$approvalRequest,$data): void {
   $before=$approvalRequest->toArray();
   $approvalRequest->update(['status'=>$data['decision'],'reason'=>$data['reason']??$approvalRequest->reason,'decided_by'=>$request->user()->id,'decided_at'=>now()]);
   PlatformAuditLog::create(['user_id'=>$request->user()->id,'resource'=>'approval_requests','record_id'=>$approvalRequest->id,'action'=>$data['decision'],'before'=>$before,'after'=>$approvalRequest->fresh()->toArray(),'ip_address'=>$request->ip(),'user_agent'=>$request->userAgent()]);
  });
  return response()->json(['message'=>$data['decision']==='approved'?'تم اعتماد الطلب.':'تم رفض الطلب.','data'=>$approvalRequest->fresh()]);
 }
}
