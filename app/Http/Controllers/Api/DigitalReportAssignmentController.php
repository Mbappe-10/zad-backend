<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DigitalEmployee;
use App\Models\User;
use App\Services\DigitalReportExport;
use App\Services\DigitalReportSchedule;
use Carbon\CarbonImmutable as Clock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DigitalReportAssignmentController extends Controller
{
    private function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isActive() && $user->isPlatformOwner(), 403, 'تقارير المنصة متاحة للمالك النشط فقط.');
        return $user;
    }

    private function assignment(Request $request, int $id): object
    {
        $user = $this->owner($request);
        $a = DB::table('digital_report_assignments')->where('id', $id)->where('owner_id', $user->id)->first();
        abort_unless($a && DigitalEmployee::where('id', $a->digital_employee_id)->where('owner_id', $user->id)->exists(), 404);
        return $a;
    }

    private function run(Request $request, int $id): object
    {
        $r = DB::table('digital_report_runs')->where('id', $id)->first();
        abort_unless($r, 404);
        $this->assignment($request, (int) $r->assignment_id);
        return $r;
    }

    public function index(Request $request)
    {
        $user = $this->owner($request);
        $assignments = DB::table('digital_report_assignments')->where('owner_id', $user->id)
            ->whereIn('digital_employee_id', DigitalEmployee::where('owner_id', $user->id)->select('id'))
            ->orderByDesc('id')->get();
        $filter = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $query = DB::table('digital_report_runs')->whereIn('assignment_id', $assignments->pluck('id'));
        $reminders = (clone $query)->where('status', 'waiting_approval')->where('reminder_at', '<=', Clock::now('UTC')->toDateTimeString())->count();
        $waiting = (clone $query)->where('status', 'waiting_approval')->count();
        $runs = $query->orderByDesc('id')->paginate(30, ['*'], 'page', $filter['page'] ?? 1);
        foreach ($runs as $r) $r->snapshot = $r->snapshot ? json_decode($r->snapshot, true, 512, JSON_THROW_ON_ERROR) : null;
        return response()->json(['assignments' => $assignments, 'runs' => $runs, 'waiting' => $waiting, 'reminders' => $reminders,
            'worker_last_seen' => Cache::get('zad:digital-reports:heartbeat:v1')]);
    }

    public function store(Request $request)
    {
        $user = $this->owner($request);
        $data = $request->validate([
            'digital_employee_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:200'],
            'report_type' => ['required', 'in:operations,sales'], 'frequency' => ['required', 'in:once,days,months'],
            'interval_value' => ['required', 'integer', 'between:1,365'], 'period_days' => ['required', 'integer', 'between:1,366'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'], 'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'reminder_hours' => ['required', 'integer', 'between:1,168'], 'creation_key' => ['required', 'uuid'],
        ]);
        $existing = DB::table('digital_report_assignments')->where('owner_id', $user->id)->where('creation_key', $data['creation_key'])->first();
        if ($existing) return response()->json(['data' => $existing]);
        $start = Clock::createFromFormat('!Y-m-d\TH:i', $data['starts_at'], 'Asia/Riyadh')->utc();
        abort_if($start->lt(Clock::now('UTC')->subMinutes(5)), 422, 'اختر موعد بداية حاضراً أو مستقبلاً.');
        $end = ! empty($data['ends_at']) ? Clock::createFromFormat('!Y-m-d\TH:i', $data['ends_at'], 'Asia/Riyadh')->utc() : null;
        $row = DB::transaction(function () use ($data, $user, $start, $end) {
            $employee = DigitalEmployee::query()->where('owner_id', $user->id)->lockForUpdate()->findOrFail($data['digital_employee_id']);
            abort_unless($employee->status === 'active', 422, 'اختر موظفاً نشطاً.');
            // Locking the employee also serializes duplicate submissions for this employee.
            $existing = DB::table('digital_report_assignments')->where('owner_id', $user->id)->where('creation_key', $data['creation_key'])->first();
            if ($existing) return $existing;
            abort_if(DB::table('digital_report_assignments')->where('owner_id', $user->id)->whereIn('status', ['active', 'paused'])->count() >= 200, 422, 'الحد الأقصى 200 تكليف نشط أو موقوف مؤقتاً.');
            $record = $data;
            $record['owner_id'] = $user->id;
            $record['timezone'] = 'Asia/Riyadh';
            $record['starts_at'] = $start->toDateTimeString();
            $record['ends_at'] = $end?->toDateTimeString();
            $record['next_run_at'] = $start->toDateTimeString();
            $record['status'] = 'active';
            $record['created_at'] = $record['updated_at'] = Clock::now('UTC')->toDateTimeString();
            $id = DB::table('digital_report_assignments')->insertGetId($record);
            return DB::table('digital_report_assignments')->where('id', $id)->first();
        });
        return response()->json(['data' => $row], 201);
    }

    public function change(Request $request, int $id)
    {
        $this->assignment($request, $id);
        $data = $request->validate(['action' => ['required', 'in:pause,resume,cancel']]);
        DB::transaction(function () use ($id, $data) {
            $a = DB::table('digital_report_assignments')->where('id', $id)->lockForUpdate()->first();
            $now = Clock::now('UTC');
            if ($data['action'] === 'resume') {
                abort_unless($a->status === 'paused', 422, 'يمكن استئناف التكليف الموقوف مؤقتاً فقط.');
                abort_if($a->ends_at && $now->gte(Clock::parse($a->ends_at, 'UTC')), 422, 'انتهت مدة التكليف.');
                $next = Clock::parse($a->next_run_at ?? $a->starts_at, 'UTC');
                if ($next->lt($now)) $next = $a->frequency === 'once' ? $now : app(DigitalReportSchedule::class)->next($a, $now);
                abort_unless($next, 422, 'لا يوجد موعد قادم ضمن مدة التكليف.');
                DB::table('digital_report_assignments')->where('id', $id)->update(['status' => 'active', 'next_run_at' => $next->toDateTimeString(), 'updated_at' => $now->toDateTimeString()]);
            } else {
                abort_unless(in_array($a->status, ['active', 'paused', 'completed'], true), 422);
                abort_if($data['action'] === 'pause' && $a->status !== 'active', 422);
                DB::table('digital_report_assignments')->where('id', $id)->update(['status' => $data['action'] === 'pause' ? 'paused' : 'cancelled', 'updated_at' => $now->toDateTimeString()]);
                if ($data['action'] === 'cancel') {
                    DB::table('digital_report_runs')->where('assignment_id', $id)->whereIn('status', ['queued', 'running'])
                        ->update(['status' => 'cancelled', 'finished_at' => $now->toDateTimeString()]);
                }
            }
        });
        return response()->json(['message' => 'تم تحديث التكليف.']);
    }

    public function review(Request $request, int $id)
    {
        $this->run($request, $id);
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:2000']]);
        $changed = DB::table('digital_report_runs')->where('id', $id)->where('status', 'waiting_approval')->update([
            'status' => $data['decision'] === 'approve' ? 'completed' : 'rejected', 'review_note' => $data['note'] ?? null,
            'reviewed_by' => $request->user()->id, 'reviewed_at' => Clock::now('UTC')->toDateTimeString(), 'reminder_at' => null,
        ]);
        abort_unless($changed, 409, 'تمت مراجعة التقرير مسبقاً أو تغيرت حالته.');
        return response()->json(['message' => 'تم تسجيل مراجعة التقرير فقط.']);
    }

    public function retry(Request $request, int $id)
    {
        $r = $this->run($request, $id);
        $a = $this->assignment($request, (int) $r->assignment_id);
        abort_if(in_array($a->status, ['paused', 'cancelled'], true), 422, 'التكليف موقوف أو ملغى.');
        $changed = DB::table('digital_report_runs')->where('id', $id)->where('status', 'failed')->where('attempts', '<', 3)
            ->update(['status' => 'queued', 'error_message' => null]);
        abort_unless($changed, 422, 'إعادة المحاولة للفاشل فقط وبحد ثلاث محاولات.');
        return response()->json(['message' => 'أعيدت جدولة المحاولة.']);
    }

    public function remind(Request $request, int $id)
    {
        $this->run($request, $id);
        DB::table('digital_report_runs')->where('id', $id)->where('status', 'waiting_approval')
            ->update(['reminder_at' => Clock::now('UTC')->addDay()->toDateTimeString()]);
        return response()->json(['message' => 'تم تأجيل التذكير 24 ساعة.']);
    }

    public function export(Request $request, int $id, string $format, DigitalReportExport $export)
    {
        $r = $this->run($request, $id);
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);
        abort_unless($r->snapshot && in_array($r->status, ['waiting_approval', 'completed', 'rejected'], true), 422, 'التقرير غير جاهز للتنزيل.');
        return $export->download($r, $format);
    }
}
