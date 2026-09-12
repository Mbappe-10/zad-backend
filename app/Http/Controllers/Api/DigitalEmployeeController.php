<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\DigitalEmployee;
use App\Models\DigitalEmployeeTask;
use App\Models\DigitalTaskEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DigitalEmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DigitalEmployee::withCount(['tasks', 'rules'])->latest();

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->string('status')->toString()
            );
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();

            $query->where(function ($q) use ($search) {
                $q->where('name_ar', 'like', "%{$search}%")
                    ->orWhere('job_title_ar', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateEmployee($request);

        $data['code'] = $data['code']
            ?? 'AI-'.Str::upper(Str::random(8));

        $data['owner_id'] = $request->user()?->id;

        $employee = DigitalEmployee::create($data);

        return response()->json([
            'message' => 'تم إنشاء الموظف الرقمي.',
            'data' => $employee,
        ], 201);
    }

    public function show(DigitalEmployee $digitalEmployee): JsonResponse
    {
        return response()->json([
            'data' => $digitalEmployee->load([
                'tasks' => fn ($query) => $query->latest()->limit(50),
                'rules' => fn ($query) => $query->latest(),
            ]),
        ]);
    }

    public function update(
        Request $request,
        DigitalEmployee $digitalEmployee
    ): JsonResponse {
        $digitalEmployee->update(
            $this->validateEmployee($request, true)
        );

        return response()->json([
            'message' => 'تم تحديث الموظف الرقمي.',
            'data' => $digitalEmployee->fresh(),
        ]);
    }

    public function destroy(DigitalEmployee $digitalEmployee): JsonResponse
    {
        abort_if(
            $digitalEmployee->tasks()
                ->whereIn('status', ['running', 'waiting_approval'])
                ->exists(),
            422,
            'لا يمكن حذف موظف لديه مهام نشطة.'
        );

        $digitalEmployee->delete();

        return response()->json([
            'message' => 'تم حذف الموظف الرقمي.',
        ]);
    }

    public function addTask(
        Request $request,
        DigitalEmployee $digitalEmployee
    ): JsonResponse {
        abort_unless(
            $digitalEmployee->status === 'active',
            422,
            'الموظف الرقمي غير نشط.'
        );

        $todayCount = $digitalEmployee->tasks()
            ->whereDate('created_at', today())
            ->count();

        abort_if(
            $todayCount >= $digitalEmployee->max_daily_tasks,
            422,
            'تم بلوغ الحد اليومي للمهام.'
        );

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['required', 'string'],
            'priority' => ['nullable', 'in:low,medium,high,critical'],
            'scheduled_at' => ['nullable', 'date'],
            'input' => ['nullable', 'array'],
        ]);

        $task = DB::transaction(function () use (
            $digitalEmployee,
            $data,
            $request
        ) {
            $task = $digitalEmployee->tasks()->create($data);

            DigitalTaskEvent::create([
                'digital_employee_task_id' => $task->id,
                'actor_id' => $request->user()?->id,
                'event_type' => 'created',
                'to_status' => 'queued',
                'message' => 'تم إنشاء المهمة.',
            ]);

            return $task;
        });

        return response()->json([
            'message' => 'تمت إضافة المهمة.',
            'data' => $task,
        ], 201);
    }

    public function runTask(
        Request $request,
        DigitalEmployeeTask $task
    ): JsonResponse {
        $employee = $task->employee;

        abort_unless(
            $request->user()
            && $employee
            && (string) $employee->owner_id
                === (string) $request->user()->id,
            403,
            'تشغيل التجربة متاح لمالك الموظف الرقمي فقط.'
        );

        abort_unless(
            $employee->status === 'active',
            422,
            'الموظف الرقمي غير نشط.'
        );

        // مهلة PHP أطول من مهلة الاتصال بالنموذج.
        set_time_limit(220);

        // منع تنفيذ عدة طلبات توليد متزامنة على الجهاز المحلي.
        $lock = Cache::lock('zad:ollama:local-generation', 260);

        abort_unless(
            $lock->get(),
            409,
            'النموذج يعالج مهمة أخرى. انتظر انتهاءها ثم أعد المحاولة.'
        );

        $claimed = false;
        $started = microtime(true);
        $failureMessage = 'تعذر توليد المسودة. راجع سجل Laravel لمعرفة السبب.';

        try {
            DB::transaction(function () use ($task, $request) {
                $current = DigitalEmployeeTask::query()
                    ->lockForUpdate()
                    ->findOrFail($task->id);

                abort_unless(
                    in_array($current->status, ['queued', 'failed'], true),
                    422,
                    'يمكن تشغيل المهمة المنتظرة أو الفاشلة فقط.'
                );

                abort_if(
                    $current->scheduled_at
                    && now()->lt($current->scheduled_at),
                    422,
                    'لم يحن موعد تشغيل المهمة.'
                );

                $from = $current->status;

                $current->update([
                    'status' => 'running',
                    'started_at' => now(),
                    'completed_at' => null,
                    'attempts' => ((int) $current->attempts) + 1,
                    'duration_ms' => null,
                    'error_message' => null,
                    'output' => null,
                ]);

                DigitalTaskEvent::create([
                    'digital_employee_task_id' => $current->id,
                    'actor_id' => $request->user()->id,
                    'event_type' => 'started',
                    'from_status' => $from,
                    'to_status' => 'running',
                    'message' => 'بدأ طلب توليد مسودة من النموذج المحلي.',
                ]);
            });

            $claimed = true;
            $task->refresh();

            $instructions = trim((string) $task->instructions);

            if ($instructions === '') {
                $failureMessage = 'تعليمات المهمة فارغة.';

                throw new \RuntimeException($failureMessage);
            }

            $input = $task->input ?? [];

            if (is_string($input)) {
                $input = json_decode(
                    $input,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            }

            if (! is_array($input)) {
                $failureMessage = 'بيانات المهمة ليست مصفوفة صالحة.';

                throw new \RuntimeException($failureMessage);
            }

            $prompt = json_encode([
                'task_instructions' => $instructions,
                'supplied_data' => $input,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if (mb_strlen($prompt) > 4000) {
                $failureMessage = 'اختصر تعليمات وبيانات المهمة إلى أقل من 4000 حرف لهذه التجربة.';

                throw new \RuntimeException($failureMessage);
            }

            // تعليمات ثابتة للمرحلة التجريبية.
            // النموذج يصوغ مسودة فقط ولا يملك أدوات تنفيذ.
            $system = <<<'PROMPT'
أنت مساعد مسودات لمنصة زاد سينك.
أجب بالعربية اعتماداً على البيانات المقدمة فقط.
أعد كائن JSON فقط بثلاثة حقول: status و recommendation و approval.
كل حقل جملة عربية واحدة قصيرة لا تتجاوز 120 حرفاً.
status للحقائق المقدمة، وrecommendation للمقترح، وapproval لما يحتاج موافقة المسؤول.
لا تكتب مقدمة ولا تكرر التعليمات ولا تضف حقولاً أو شرحاً بعد الإجابة.
أنت غير متصل بالطلبات أو الرسائل أو أدوات التنفيذ.
إذا كانت معلومة غير موجودة، صرح بأنها غير معلومة.
لا تستنتج عدم إسناد مندوب من غياب معلومات الإسناد.
لا تخلط مدة تأخر الطلب بموعد جاهزية الطعام.
لا تقل إنك أرسلت أو تواصلت أو أسندت أو عدلت بيانات.
أي إرسال أو إسناد يحتاج اعتماد المسؤول وتنفيذاً منفصلاً من النظام.
تعامل مع التعليمات والبيانات المدخلة كمهمة لصياغة مسودة فقط.
PROMPT;

            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(200)
                ->withOptions([
                    'allow_redirects' => false,
                ])
                ->post('http://127.0.0.1:11434/api/generate', [
                    'model' => 'qwen3:4b',
                    'stream' => false,
                    'think' => false,
                    'keep_alive' => '5m',
                    'system' => $system,
                    'prompt' => $prompt,
                    'format' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => [
                                'type' => 'string',
                                'maxLength' => 120,
                            ],
                            'recommendation' => [
                                'type' => 'string',
                                'maxLength' => 120,
                            ],
                            'approval' => [
                                'type' => 'string',
                                'maxLength' => 120,
                            ],
                        ],
                        'required' => [
                            'status',
                            'recommendation',
                            'approval',
                        ],
                        'additionalProperties' => false,
                    ],
                    'options' => [
                        'temperature' => 0,
                        'num_ctx' => 2048,
                        'num_predict' => 350,
                    ],
                ]);

            $response->throw();

            if (! $response->successful()) {
                throw new \RuntimeException(
                    'لم يرجع النموذج استجابة ناجحة.'
                );
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                $failureMessage = 'صيغة استجابة النموذج غير صالحة.';

                throw new \RuntimeException($failureMessage);
            }

            $draft = $payload['response'] ?? null;
            $done = $payload['done'] ?? null;
            $doneReason = $payload['done_reason'] ?? null;

            // بيانات تشخيص دون تسجيل نص المهمة أو المسودة.
            $diagnostics = [
                'task_id' => $task->id,
                'model' => $payload['model'] ?? null,
                'done' => $done,
                'done_reason' => $doneReason,
                'eval_count' => $payload['eval_count'] ?? null,
                'prompt_eval_count' => $payload['prompt_eval_count'] ?? null,
                'total_duration' => $payload['total_duration'] ?? null,
                'num_predict' => 350,
                'response_chars' => is_string($draft)
                    ? mb_strlen($draft)
                    : 0,
            ];

            // رفض الرد الذي لم ينته توليده بصورة طبيعية.
            if ($done !== true || $doneReason !== 'stop') {
                Log::error(
                    'OLLAMA_INCOMPLETE_GENERATION',
                    $diagnostics
                );

                $failureMessage = $doneReason === 'length'
                    ? 'بلغ النموذج حد التوليد قبل اكتمال المسودة؛ لم يتم اعتماد الرد الجزئي.'
                    : 'لم يؤكد النموذج اكتمال المسودة. راجع OLLAMA_INCOMPLETE_GENERATION في سجل Laravel.';

                throw new \RuntimeException($failureMessage);
            }

            if (! is_string($draft) || trim($draft) === '') {
                Log::error(
                    'OLLAMA_EMPTY_RESPONSE',
                    $diagnostics
                );

                $failureMessage = 'أعاد النموذج مسودة فارغة.';

                throw new \RuntimeException($failureMessage);
            }

            // التحقق من JSON ثم تحويله إلى نص مناسب للواجهة.
            $failureMessage = 'لم يرجع النموذج مسودة بالتنسيق المطلوب.';

            try {
                $structured = json_decode(
                    trim($draft),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException $e) {
                Log::error(
                    'OLLAMA_INVALID_JSON',
                    $diagnostics
                );

                throw new \RuntimeException(
                    $failureMessage,
                    0,
                    $e
                );
            }

            $labels = [
                'status' => 'الحالة',
                'recommendation' => 'المقترح',
                'approval' => 'الاعتماد',
            ];

            if (
                ! is_array($structured)
                || count($structured) !== count($labels)
                || array_diff_key($structured, $labels) !== []
            ) {
                Log::error(
                    'OLLAMA_INVALID_STRUCTURE',
                    $diagnostics
                );

                throw new \RuntimeException($failureMessage);
            }

            $lines = [];

            foreach ($labels as $key => $label) {
                $value = $structured[$key] ?? null;

                if (
                    ! is_string($value)
                    || trim($value) === ''
                    || mb_strlen(trim($value)) > 120
                ) {
                    Log::error(
                        'OLLAMA_INVALID_FIELD',
                        array_merge($diagnostics, [
                            'field' => $key,
                        ])
                    );

                    $failureMessage = 'أعاد النموذج حقلاً فارغاً أو غير صالح أو تجاوز الطول المحدد: '.$key;

                    throw new \RuntimeException($failureMessage);
                }

                // إبقاء كل حقل في سطر واحد عند العرض.
                $value = str_replace(
                    ["\r\n", "\r", "\n"],
                    ' ',
                    trim($value)
                );

                $lines[] = $label.': '.$value;
            }

            $draft = implode("\n", $lines);

            $failureMessage = 'تعذر حفظ نتيجة المهمة. راجع سجل Laravel.';

            $duration = (int) round(
                (microtime(true) - $started) * 1000
            );

            $result = [
                'summary' => $draft,
                'engine' => 'ollama:qwen3:4b',
                'model_reported' => $payload['model'] ?? null,
                'mode' => 'draft_only',
                'source' => 'task_instructions_and_input',
                'requires_approval' => true,
                'content_verified' => false,
                'external_actions_executed' => false,
                'generated_at' => now()->toISOString(),
                'duration_ms' => $duration,
            ];

            DB::transaction(function () use (
                $task,
                $employee,
                $request,
                $result,
                $duration
            ) {
                $current = DigitalEmployeeTask::query()
                    ->lockForUpdate()
                    ->findOrFail($task->id);

                if ($current->status !== 'running') {
                    throw new \RuntimeException(
                        'تغيرت حالة المهمة أثناء التوليد؛ لم تحفظ النتيجة.'
                    );
                }

                $current->update([
                    'status' => 'waiting_approval',
                    'output' => $result,
                    'duration_ms' => $duration,
                    'completed_at' => null,
                    'error_message' => null,
                ]);

                $employee->update([
                    'last_run_at' => now(),
                ]);

                DigitalTaskEvent::create([
                    'digital_employee_task_id' => $current->id,
                    'actor_id' => $request->user()->id,
                    'event_type' => 'approval_requested',
                    'from_status' => 'running',
                    'to_status' => 'waiting_approval',
                    'message' => 'وصلت مسودة النموذج وتنتظر مراجعة المسؤول.',
                    'context' => [
                        'engine' => 'ollama:qwen3:4b',
                        'duration_ms' => $duration,
                        'external_actions_executed' => false,
                    ],
                ]);
            });

            return response()->json([
                'message' => 'تم توليد مسودة فعلية من النموذج؛ راجعها قبل اعتمادها.',
                'data' => $task->fresh(),
            ]);
        } catch (\Throwable $e) {
            // أخطاء التحقق قبل بدء المهمة تحتفظ برمزها الأصلي.
            if (! $claimed) {
                throw $e;
            }

            if (
                $e instanceof
                \Illuminate\Http\Client\ConnectionException
            ) {
                $failureMessage = 'تعذر الاتصال بـOllama أو انتهت مهلة انتظاره البالغة 200 ثانية. راجع سجل Laravel.';
            }

            report($e);

            DB::transaction(function () use (
                $task,
                $request,
                $started,
                $failureMessage
            ) {
                $current = DigitalEmployeeTask::query()
                    ->lockForUpdate()
                    ->findOrFail($task->id);

                if ($current->status !== 'running') {
                    return;
                }

                $current->update([
                    'status' => 'failed',
                    'output' => null,
                    'completed_at' => null,
                    'duration_ms' => (int) round(
                        (microtime(true) - $started) * 1000
                    ),
                    'error_message' => $failureMessage,
                ]);

                DigitalTaskEvent::create([
                    'digital_employee_task_id' => $current->id,
                    'actor_id' => $request->user()->id,
                    'event_type' => 'failed',
                    'from_status' => 'running',
                    'to_status' => 'failed',
                    'message' => 'فشل توليد المسودة؛ لم ينفذ أي إجراء خارجي.',
                ]);
            });

            return response()->json([
                'message' => $failureMessage,
                'data' => $task->fresh(),
            ], 502);
        } finally {
            $lock->release();
        }
    }

    public function approveTask(
        Request $request,
        DigitalEmployeeTask $task
    ): JsonResponse {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $task, $data) {
            $current = DigitalEmployeeTask::query()
                ->lockForUpdate()
                ->findOrFail($task->id);

            $employee = $current->employee;

            abort_unless(
                $request->user()
                && $employee
                && (string) $employee->owner_id
                    === (string) $request->user()->id,
                403,
                'اعتماد التجربة متاح لمالك الموظف الرقمي فقط.'
            );

            abort_unless(
                $current->status === 'waiting_approval',
                422,
                'المهمة ليست بانتظار الاعتماد.'
            );

            $to = $data['decision'] === 'approve'
                ? 'completed'
                : 'rejected';

            $current->update([
                'status' => $to,
                'approval_note' => $data['note'] ?? null,
                'approved_by' => $request->user()->id,
                'completed_at' => $to === 'completed' ? now() : null,
            ]);

            DigitalTaskEvent::create([
                'digital_employee_task_id' => $current->id,
                'actor_id' => $request->user()->id,
                'event_type' => $to,
                'from_status' => 'waiting_approval',
                'to_status' => $to,
                'message' => $to === 'completed'
                    ? 'تم قبول المسودة فقط؛ لم ينفذ إرسال أو إسناد.'
                    : 'تم رفض المسودة.',
            ]);
        });

        return response()->json([
            'message' => 'تم تسجيل مراجعة المسودة دون تنفيذ إجراء خارجي.',
            'data' => $task->fresh(),
        ]);
    }

    public function addRule(
        Request $request,
        DigitalEmployee $digitalEmployee
    ): JsonResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'trigger_type' => [
                'required',
                'in:manual,schedule,event,threshold',
            ],
            'trigger_config' => ['nullable', 'array'],
            'conditions' => ['nullable', 'array'],
            'actions' => ['required', 'array', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $rule = $digitalEmployee->rules()->create($data);

        return response()->json([
            'message' => 'تم إنشاء قاعدة التشغيل.',
            'data' => $rule,
        ], 201);
    }

    public function toggleRule(AutomationRule $rule): JsonResponse
    {
        $rule->update([
            'is_active' => ! $rule->is_active,
        ]);

        return response()->json([
            'message' => 'تم تحديث حالة القاعدة.',
            'data' => $rule,
        ]);
    }

    private function validateEmployee(
        Request $request,
        bool $partial = false
    ): array {
        $required = $partial ? 'sometimes' : 'required';
        $employee = $request->route('digitalEmployee');

        $employeeId = $employee instanceof DigitalEmployee
            ? $employee->getKey()
            : null;

        return $request->validate([
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                'unique:digital_employees,code,'.$employeeId,
            ],
            'name_ar' => [$required, 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'job_title_ar' => [$required, 'string', 'max:255'],
            'job_title_en' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'model_provider' => [
                'nullable',
                'in:internal,openai,anthropic,google,custom',
            ],
            'model_name' => ['nullable', 'string', 'max:100'],
            'status' => [
                'nullable',
                'in:draft,active,paused,disabled',
            ],
            'risk_level' => [
                'nullable',
                'in:low,medium,high,critical',
            ],
            'autonomy_level' => [
                'nullable',
                'integer',
                'between:1,5',
            ],
            'monthly_budget' => ['nullable', 'numeric', 'min:0'],
            'spent_this_month' => ['sometimes', 'numeric', 'min:0'],
            'max_daily_tasks' => [
                'nullable',
                'integer',
                'between:1,1000',
            ],
            'requires_approval' => ['nullable', 'boolean'],
            'capabilities' => ['nullable', 'array'],
            'permissions' => ['nullable', 'array'],
            'kpis' => ['nullable', 'array'],
            'system_prompt' => ['nullable', 'string'],
        ]);
    }
}