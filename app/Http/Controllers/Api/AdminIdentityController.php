<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Models\PlatformRecord;
use App\Models\User;
use App\Services\AdminIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminIdentityController extends Controller
{
    public function __invoke(Request $request, string $identity, ?int $record = null, ?string $action = null): JsonResponse
    {
        abort_unless(in_array($identity,['users','roles'],true),404);
        $actor = $request->user();
        abort_unless($actor instanceof User,401);
        $service = app(AdminIdentityService::class);
        if ($request->isMethod('get')) {
            abort_if($action !== null,404);
            $service->authorize($actor,$identity,'view');
            $query = PlatformRecord::where('resource',$identity);
            if ($record !== null) return response()->json(['data'=>$service->row($query->findOrFail($record))]);
            foreach (['status','user_type','role_id','type','employee_type'] as $filter) {
                if ($request->filled($filter) && $request->input($filter) !== 'all') {
                    $query->where($filter === 'status' ? 'status' : 'payload->'.$filter, $request->input($filter));
                }
            }
            if ($request->filled('search')) {
                $term = '%'.mb_substr((string)$request->input('search'),0,200).'%';
                $query->where(function($q) use($term) {
                    foreach (['name','name_ar','name_en','email','code'] as $field) $q->orWhere('payload->'.$field,'like',$term);
                });
            }
            $perPage = max(1,min(200,(int)$request->input('per_page', $identity === 'roles' ? 200 : 15)));
            $page = $query->latest('id')->paginate($perPage);
            return response()->json([
                'data'=>$page->getCollection()->map(fn($r)=>$service->row($r))->values(),
                'current_page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'per_page'=>$page->perPage(),'total'=>$page->total(),
                'meta'=>['current_page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'per_page'=>$page->perPage(),'total'=>$page->total()],
                'summary'=>[
                    'total'=>PlatformRecord::where('resource',$identity)->count(),
                    'active'=>PlatformRecord::where('resource',$identity)->where('status','active')->count(),
                    'suspended'=>PlatformRecord::where('resource',$identity)->where('status','suspended')->count(),
                    'human'=>PlatformRecord::where('resource',$identity)->where('payload->user_type','human')->count(),
                    'digital'=>PlatformRecord::where('resource',$identity)->where('payload->user_type','digital')->count(),
                ],
            ]);
        }
        $creating = $record === null;
        abort_unless(($creating && $request->isMethod('post')) || (!$creating && in_array($request->method(),['POST','PUT','PATCH','DELETE'],true)),405);
        abort_unless($action === null || $action === 'status' || ($identity === 'roles' && $action === 'duplicate'),404);
        $operation = $creating || $action === 'duplicate' ? 'create' : ($request->isMethod('delete') ? 'delete' : 'update');
        $service->authorize($actor,$identity,$operation);
        return DB::transaction(function() use($request,$identity,$record,$action,$actor,$service,$creating,$operation) {
            $entry = $creating ? new PlatformRecord(['resource'=>$identity,'status'=>'pending','payload'=>[],'created_by'=>$actor->id])
                : PlatformRecord::where('resource',$identity)->lockForUpdate()->findOrFail($record);
            $before = $creating ? null : AdminIdentityService::safe($entry->payload ?? []);
            if (!$creating) $service->protect($entry,$actor);
            if ($operation === 'delete') {
                $service->remove($entry,$actor);
            } else {
                $input = $request->all();
                if ($action === 'status') {
                    $request->validate(['status'=>'required|string']);
                    $input = [...($entry->payload ?? []),'status'=>$request->input('status')];
                }
                if ($action === 'duplicate') {
                    $input = AdminIdentityService::safe($entry->payload ?? []);
                    $input['code'] = mb_substr($input['code'],0,65).'_'.strtolower(\Illuminate\Support\Str::random(8));
                    $input['name_ar'] .= ' (نسخة)';
                    $entry = new PlatformRecord(['resource'=>'roles','status'=>'inactive','payload'=>[],'created_by'=>$actor->id]);
                    $input['status'] = 'inactive';
                    $before = null;
                }
                $entry->updated_by = $actor->id;
                $entry->save();
                if ($identity === 'users') $service->saveUser($entry,$input,$actor);
                else $service->saveRole($entry,$input,$actor);
            }
            PlatformAuditLog::create([
                'user_id'=>$actor->id,'resource'=>$identity,'record_id'=>$entry->id,'action'=>$operation,
                'before'=>$before,'after'=>$operation === 'delete' ? null : AdminIdentityService::safe($entry->payload ?? []),
                'ip_address'=>$request->ip(),'user_agent'=>mb_substr((string)$request->userAgent(),0,1000),
            ]);
            return response()->json(['message'=>'تم حفظ التغيير في النظام.','data'=>$operation === 'delete' ? null : $service->row($entry)],$operation==='create'?201:200);
        });
    }
}
