<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\DigitalEmployee;
use App\Models\DigitalEmployeeTask;
use App\Models\DigitalTaskEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DigitalEmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = DigitalEmployee::withCount(['tasks', 'rules'])->latest();
        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }
        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->where(fn ($x) => $x->where('name_ar', 'like', "%$s%")->orWhere('job_title_ar', 'like', "%$s%")->orWhere('code', 'like', "%$s%"));
        }

        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validateEmployee($request);
        $d['code'] = $d['code'] ?? 'AI-'.Str::upper(Str::random(8));
        $d['owner_id'] = $request->user()?->id;
        $e = DigitalEmployee::create($d);

        return response()->json(['message' => 'تم إنشاء الموظف الرقمي.', 'data' => $e], 201);
    }

    public function show(DigitalEmployee $digitalEmployee): JsonResponse
    {
        return response()->json(['data' => $digitalEmployee->load(['tasks' => fn ($q) => $q->latest()->limit(50), 'rules' => fn ($q) => $q->latest()])]);
    }

    public function update(Request $request, DigitalEmployee $digitalEmployee): JsonResponse
    {
        $digitalEmployee->update($this->validateEmployee($request, true));

        return response()->json(['message' => 'تم تحديث الموظف الرقمي.', 'data' => $digitalEmployee->fresh()]);
    }

    public function destroy(DigitalEmployee $digitalEmployee): JsonResponse
    {
        abort_if($digitalEmployee->tasks()->whereIn('status', ['running', 'waiting_approval'])->exists(), 422, 'لا يمكن حذف موظف لديه مهام نشطة.');
        $digitalEmployee->delete();

        return response()->json(['message' => 'تم حذف الموظف الرقمي.']);
    }

    public function addTask(Request $request, DigitalEmployee $digitalEmployee): JsonResponse
    {
        abort_unless($digitalEmployee->status === 'active', 422, 'الموظف الرقمي غير نشط.');
        $today = $digitalEmployee->tasks()->whereDate('created_at', today())->count();
        abort_if($today >= $digitalEmployee->max_daily_tasks, 422, 'تم بلوغ الحد اليومي للمهام.');
        $d = $request->validate(['title' => ['required', 'string', 'max:255'], 'instructions' => ['required', 'string'], 'priority' => ['nullable', 'in:low,medium,high,critical'], 'scheduled_at' => ['nullable', 'date'], 'input' => ['nullable', 'array']]);
        $task = DB::transaction(function () use ($digitalEmployee, $d, $request) {
            $task = $digitalEmployee->tasks()->create($d);
            DigitalTaskEvent::create(['digital_employee_task_id' => $task->id, 'actor_id' => $request->user()?->id, 'event_type' => 'created', 'to_status' => 'queued', 'message' => 'تم إنشاء المهمة.']);

            return $task;
        });

        return response()->json(['message' => 'تمت إضافة المهمة.', 'data' => $task], 201);
    }

    public function runTask(Request $request, DigitalEmployeeTask $task): JsonResponse
    {
        abort_if(in_array($task->status, ['running', 'completed', 'waiting_approval'], true), 422, 'لا يمكن تشغيل المهمة بهذه الحالة.');
        $employee = $task->employee;
        abort_unless($employee && $employee->status === 'active', 422, 'الموظف الرقمي غير نشط.');
        $from = $task->status;
        $started = microtime(true);
        $task->update(['status' => 'running', 'started_at' => now(), 'attempts' => $task->attempts + 1, 'error_message' => null]);
        DigitalTaskEvent::create(['digital_employee_task_id' => $task->id, 'actor_id' => $request->user()?->id, 'event_type' => 'started', 'from_status' => $from, 'to_status' => 'running', 'message' => 'بدأ محرك سير العمل تنفيذ المهمة.']);
        try {
            $result = ['summary' => 'تمت معالجة المهمة بواسطة محرك سير العمل الآمن.', 'instructions' => $task->instructions, 'capabilities' => $employee->capabilities ?? [], 'requires_approval' => $employee->requires_approval, 'engine' => $employee->model_provider.':'.$employee->model_name, 'generated_at' => now()->toISOString()];
            $status = $employee->requires_approval ? 'waiting_approval' : 'completed';
            $duration = (int) round((microtime(true) - $started) * 1000);
            $task->update(['status' => $status, 'output' => $result, 'duration_ms' => $duration, 'completed_at' => $status === 'completed' ? now() : null]);
            $employee->update(['last_run_at' => now()]);
            DigitalTaskEvent::create(['digital_employee_task_id' => $task->id, 'actor_id' => $request->user()?->id, 'event_type' => $status === 'completed' ? 'completed' : 'approval_requested', 'from_status' => 'running', 'to_status' => $status, 'message' => $status === 'completed' ? 'اكتملت المهمة.' : 'المهمة بانتظار الاعتماد البشري.', 'context' => ['duration_ms' => $duration]]);
        } catch (\Throwable $e) {
            $task->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            DigitalTaskEvent::create(['digital_employee_task_id' => $task->id, 'actor_id' => $request->user()?->id, 'event_type' => 'failed', 'from_status' => 'running', 'to_status' => 'failed', 'message' => $e->getMessage()]);
            throw $e;
        }

        return response()->json(['message' => 'تم تنفيذ المهمة.', 'data' => $task->fresh()]);
    }

    public function approveTask(Request $request, DigitalEmployeeTask $task): JsonResponse
    {
        abort_unless($task->status === 'waiting_approval', 422, 'المهمة ليست بانتظار الاعتماد.');
        $d = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string']]);
        $to = $d['decision'] === 'approve' ? 'completed' : 'rejected';
        $task->update(['status' => $to, 'approval_note' => $d['note'] ?? null, 'approved_by' => $request->user()?->id, 'completed_at' => $to === 'completed' ? now() : null]);
        DigitalTaskEvent::create(['digital_employee_task_id' => $task->id, 'actor_id' => $request->user()?->id, 'event_type' => $to, 'from_status' => 'waiting_approval', 'to_status' => $to, 'message' => $d['note'] ?? 'تم تسجيل قرار الاعتماد.']);

        return response()->json(['message' => 'تم تسجيل قرار الاعتماد.', 'data' => $task->fresh()]);
    }

    public function addRule(Request $request, DigitalEmployee $digitalEmployee): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'trigger_type' => ['required', 'in:manual,schedule,event,threshold'], 'trigger_config' => ['nullable', 'array'], 'conditions' => ['nullable', 'array'], 'actions' => ['required', 'array', 'min:1'], 'is_active' => ['nullable', 'boolean']]);
        $rule = $digitalEmployee->rules()->create($d);

        return response()->json(['message' => 'تم إنشاء قاعدة التشغيل.', 'data' => $rule], 201);
    }

    public function toggleRule(AutomationRule $rule): JsonResponse
    {
        $rule->update(['is_active' => ! $rule->is_active]);

        return response()->json(['message' => 'تم تحديث حالة القاعدة.', 'data' => $rule]);
    }

    private function validateEmployee(Request $request, bool $partial = false): array
    {
        $p = $partial ? 'sometimes' : 'required';

        return $request->validate(['code' => ['sometimes', 'nullable', 'string', 'max:50', 'unique:digital_employees,code,'.$request->route('digitalEmployee')?->id], 'name_ar' => [$p, 'string', 'max:255'], 'name_en' => ['nullable', 'string', 'max:255'], 'job_title_ar' => [$p, 'string', 'max:255'], 'job_title_en' => ['nullable', 'string', 'max:255'], 'department' => ['nullable', 'string', 'max:255'], 'model_provider' => ['nullable', 'in:internal,openai,anthropic,google,custom'], 'model_name' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:draft,active,paused,disabled'], 'risk_level' => ['nullable', 'in:low,medium,high,critical'], 'autonomy_level' => ['nullable', 'integer', 'between:1,5'], 'monthly_budget' => ['nullable', 'numeric', 'min:0'], 'spent_this_month' => ['sometimes', 'numeric', 'min:0'], 'max_daily_tasks' => ['nullable', 'integer', 'between:1,1000'], 'requires_approval' => ['nullable', 'boolean'], 'capabilities' => ['nullable', 'array'], 'permissions' => ['nullable', 'array'], 'kpis' => ['nullable', 'array'], 'system_prompt' => ['nullable', 'string']]);
    }
}
