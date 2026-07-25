<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Models\PlatformRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class PlatformRecordController extends Controller {
    private const RESOURCES = ['orders','customers','families','stores','products','categories','drivers','vehicles','cities','zones','wallet','wallets','transactions','payments','commissions','notifications','support','content','reports','governance','decisions','roles','permissions','users','subscriptions','ads','coupons','offers','logs','hr','autonomous-operations','analytics','admins','settings'];
    private function guard(string $resource): void { abort_unless(in_array($resource,self::RESOURCES,true),404,'المورد غير معروف.'); }
    public function index(Request $request,string $resource): JsonResponse {
        $this->guard($resource);
        $query=PlatformRecord::query()->where('resource',$resource);
        if($request->filled('status')) $query->where('status',$request->string('status'));
        if($request->filled('search')) { $s=$request->string('search')->toString(); $query->where('payload','like','%'.$s.'%'); }
        $perPage=min(max((int)$request->input('per_page',50),1),200);
        return response()->json($query->latest()->paginate($perPage));
    }
    public function store(Request $request,string $resource): JsonResponse {
        $this->guard($resource);
        $data=$request->validate(['external_key'=>['nullable','string','max:120'],'status'=>['nullable','string','max:40'],'payload'=>['required','array']]);
        $record=PlatformRecord::create([...$data,'resource'=>$resource,'status'=>$data['status']??'active','created_by'=>$request->user()?->id,'updated_by'=>$request->user()?->id]);
        $this->audit($request,$record,'created',null,$record->toArray());
        return response()->json(['message'=>'تم إنشاء السجل بنجاح.','data'=>$record],201);
    }
    public function show(string $resource,PlatformRecord $record): JsonResponse { $this->guard($resource); abort_unless($record->resource===$resource,404); return response()->json(['data'=>$record]); }
    public function update(Request $request,string $resource,PlatformRecord $record): JsonResponse {
        $this->guard($resource); abort_unless($record->resource===$resource,404); $before=$record->toArray();
        $data=$request->validate(['external_key'=>['sometimes','nullable','string','max:120'],'status'=>['sometimes','string','max:40'],'payload'=>['sometimes','array']]);
        $record->update([...$data,'updated_by'=>$request->user()?->id]); $this->audit($request,$record,'updated',$before,$record->fresh()->toArray());
        return response()->json(['message'=>'تم تحديث السجل بنجاح.','data'=>$record->fresh()]);
    }
    public function destroy(Request $request,string $resource,PlatformRecord $record): JsonResponse { $this->guard($resource); abort_unless($record->resource===$resource,404); $before=$record->toArray(); $record->delete(); $this->audit($request,$record,'deleted',$before,null); return response()->json(['message'=>'تم حذف السجل بنجاح.']); }
    public function bulk(Request $request,string $resource): JsonResponse {
        $this->guard($resource); $data=$request->validate(['ids'=>['required','array','min:1'],'ids.*'=>['integer'],'action'=>['required','in:delete,archive,activate,deactivate,approve,reject'],'status'=>['nullable','string']]);
        $records=PlatformRecord::where('resource',$resource)->whereIn('id',$data['ids'])->get();
        foreach($records as $record){ $before=$record->toArray(); if($data['action']==='delete')$record->delete(); else {$status=$data['status']??match($data['action']){'archive'=>'archived','activate'=>'active','deactivate'=>'inactive','approve'=>'approved','reject'=>'rejected'}; $record->update(['status'=>$status,'updated_by'=>$request->user()?->id]);} $this->audit($request,$record,'bulk_'.$data['action'],$before,$record->fresh()?->toArray()); }
        return response()->json(['message'=>'تم تنفيذ الإجراء الجماعي.','affected'=>$records->count()]);
    }
    private function audit(Request $request,PlatformRecord $record,string $action,?array $before,?array $after): void { PlatformAuditLog::create(['user_id'=>$request->user()?->id,'resource'=>$record->resource,'record_id'=>$record->id,'action'=>$action,'before'=>$before,'after'=>$after,'ip_address'=>$request->ip(),'user_agent'=>Arr::limit($request->userAgent()??'',1000)]); }
}
