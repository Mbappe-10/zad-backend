<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\AutomationRuleRun;
use App\Models\DigitalEmployee;
use App\Models\DigitalEmployeeTask;
use App\Models\DigitalTaskEvent;
use App\Models\EmployeeProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkforceController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $taskCounts = DigitalEmployeeTask::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $completed = (int) ($taskCounts['completed'] ?? 0);
        $failed = (int) ($taskCounts['failed'] ?? 0);
        $decided = $completed + $failed + (int) ($taskCounts['rejected'] ?? 0);

        return response()->json(['data' => [
            'human_employees' => EmployeeProfile::where('status', 'active')->count(),
            'digital_employees' => DigitalEmployee::where('status', 'active')->count(),
            'tasks_total' => DigitalEmployeeTask::count(),
            'tasks_waiting_approval' => (int) ($taskCounts['waiting_approval'] ?? 0),
            'tasks_running' => (int) ($taskCounts['running'] ?? 0),
            'tasks_failed' => $failed,
            'success_rate' => $decided > 0 ? round(($completed / $decided) * 100, 1) : 0,
            'active_rules' => AutomationRule::where('is_active', true)->count(),
            'monthly_budget' => (float) DigitalEmployee::sum('monthly_budget'),
            'monthly_spend' => (float) DigitalEmployee::sum('spent_this_month'),
            'status_breakdown' => $taskCounts,
            'recent_tasks' => DigitalEmployeeTask::with('employee:id,name_ar,job_title_ar')->latest()->limit(8)->get(),
        ]]);
    }

    public function employees(Request $request): JsonResponse
    {
        $q = EmployeeProfile::with(['department:id,name_ar,name_en', 'jobTitle:id,name_ar,name_en'])->latest();
        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->where(fn ($x) => $x->where('name_ar', 'like', "%$s%")->orWhere('employee_number', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        return response()->json($q->paginate(min(100, max(10, $request->integer('per_page', 20)))));
    }

    public function storeEmployee(Request $request): JsonResponse
    {
        $data = $this->validateEmployee($request);
        $data['employee_number'] = $data['employee_number'] ?? 'EMP-'.Str::upper(Str::random(8));

        return response()->json(['message' => 'تم إنشاء ملف الموظف.', 'data' => EmployeeProfile::create($data)], 201);
    }

    public function updateEmployee(Request $request, EmployeeProfile $employee): JsonResponse
    {
        $employee->update($this->validateEmployee($request, true, $employee->id));

        return response()->json(['message' => 'تم تحديث الموظف.', 'data' => $employee->fresh()]);
    }

    public function destroyEmployee(EmployeeProfile $employee): JsonResponse
    {
        $employee->delete();

        return response()->json(['message' => 'تم حذف ملف الموظف.']);
    }

    public function tasks(Request $request): JsonResponse
    {
        $q = DigitalEmployeeTask::with('employee:id,name_ar,job_title_ar')->latest();
        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }
        if ($request->filled('employee_id')) {
            $q->where('digital_employee_id', $request->integer('employee_id'));
        }

        return response()->json($q->paginate(min(100, max(10, $request->integer('per_page', 20)))));
    }

    public function taskTimeline(DigitalEmployeeTask $task): JsonResponse
    {
        return response()->json(['data' => DigitalTaskEvent::where('digital_employee_task_id', $task->id)->with('actor:id,name,email')->latest()->get()]);
    }

    public function cancelTask(Request $request, DigitalEmployeeTask $task): JsonResponse
    {
        abort_if(in_array($task->status, ['completed', 'rejected', 'cancelled'], true), 422, 'لا يمكن إلغاء المهمة بهذه الحالة.');
        $from = $task->status;
        $task->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->event($task, $request, 'cancelled', $from, 'cancelled', 'تم إلغاء المهمة.');

        return response()->json(['message' => 'تم إلغاء المهمة.', 'data' => $task->fresh()]);
    }

    public function retryTask(Request $request, DigitalEmployeeTask $task): JsonResponse
    {
        abort_unless(in_array($task->status, ['failed', 'rejected', 'cancelled'], true), 422, 'إعادة المحاولة متاحة للمهام المتوقفة فقط.');
        $from = $task->status;
        $task->update(['status' => 'queued', 'error_message' => null, 'cancelled_at' => null]);
        $this->event($task, $request, 'retried', $from, 'queued', 'تمت إعادة المهمة إلى قائمة الانتظار.');

        return response()->json(['message' => 'تمت إعادة جدولة المهمة.', 'data' => $task->fresh()]);
    }

    public function rules(Request $request): JsonResponse
    {
        $q = AutomationRule::with('employee:id,name_ar')->latest();
        if ($request->filled('active')) {
            $q->where('is_active', $request->boolean('active'));
        }

        return response()->json(['data' => $q->get()]);
    }

    public function runRule(Request $request, AutomationRule $rule): JsonResponse
    {
        abort_unless($rule->is_active, 422, 'قاعدة التشغيل غير مفعلة.');
        $run = AutomationRuleRun::create(['automation_rule_id' => $rule->id, 'triggered_by' => $request->user()?->id, 'status' => 'running', 'input' => $request->input('input', []), 'started_at' => now()]);
        try {
            $actions = $rule->actions ?? [];
            $created = [];
            if ($rule->digital_employee_id) {
                foreach ($actions as $action) {
                    $created[] = DigitalEmployeeTask::create(['digital_employee_id' => $rule->digital_employee_id, 'title' => $action['title'] ?? $rule->name, 'instructions' => $action['instructions'] ?? 'تنفيذ قاعدة التشغيل: '.$rule->name, 'priority' => $action['priority'] ?? 'medium', 'input' => $request->input('input', [])])->id;
                }
            }
            $run->update(['status' => 'completed', 'output' => ['created_task_ids' => $created, 'actions_count' => count($actions)], 'completed_at' => now()]);
            $rule->update(['last_triggered_at' => now()]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => $e->getMessage(), 'completed_at' => now()]);
            throw $e;
        }

        return response()->json(['message' => 'تم تشغيل القاعدة.', 'data' => $run->fresh()]);
    }

    public function events(Request $request): JsonResponse
    {
        $q = DigitalTaskEvent::with(['task:id,title,digital_employee_id', 'actor:id,name,email'])->latest();
        if ($request->filled('event_type')) {
            $q->where('event_type', $request->string('event_type'));
        }

        return response()->json($q->paginate(min(100, max(10, $request->integer('per_page', 30)))));
    }

    private function event(DigitalEmployeeTask $task, Request $request, string $type, ?string $from, ?string $to, string $message, array $context = []): void
    {
        DigitalTaskEvent::create(['digital_employee_task_id' => $task->id, 'actor_id' => $request->user()?->id, 'event_type' => $type, 'from_status' => $from, 'to_status' => $to, 'message' => $message, 'context' => $context]);
    }

    private function validateEmployee(Request $request, bool $partial = false, ?int $id = null): array
    {
        $p = $partial ? 'sometimes' : 'required';

        return $request->validate(['employee_number' => ['sometimes', 'nullable', 'string', 'max:50', 'unique:employee_profiles,employee_number,'.($id ?? 'NULL')], 'user_id' => ['nullable', 'exists:users,id'], 'department_id' => ['nullable', 'exists:departments,id'], 'job_title_id' => ['nullable', 'exists:job_titles,id'], 'name_ar' => [$p, 'string', 'max:255'], 'name_en' => ['nullable', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'employment_type' => ['nullable', 'in:full_time,part_time,contract,temporary'], 'status' => ['nullable', 'in:active,on_leave,suspended,terminated'], 'hire_date' => ['nullable', 'date'], 'monthly_cost' => ['nullable', 'numeric', 'min:0'], 'skills' => ['nullable', 'array'], 'kpis' => ['nullable', 'array'], 'notes' => ['nullable', 'string']]);
    }
}
