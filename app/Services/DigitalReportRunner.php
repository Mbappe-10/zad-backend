<?php

namespace App\Services;

use App\Models\DigitalEmployee;
use App\Models\User;
use Carbon\CarbonImmutable as Clock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DigitalReportRunner
{
    public function tick(): void
    {
        $lock = Cache::lock('zad:digital-reports:runner:v1', 600);
        if (! $lock->get()) return;
        try {
            $now = Clock::now('UTC');
            Cache::put('zad:digital-reports:heartbeat:v1', $now->toIso8601String(), 86400);
            // A crashed read-only run may be retried manually; never silently duplicate it.
            DB::table('digital_report_runs')->where('status', 'running')
                ->where('started_at', '<', $now->subMinutes(10)->toDateTimeString())
                ->update(['status' => 'failed', 'error_message' => 'توقف العامل أثناء الاستخراج؛ يمكن إعادة المحاولة.', 'updated_at' => $now->toDateTimeString()]);
            $ids = DB::table('digital_report_assignments')->where('status', 'active')
                ->whereNotNull('next_run_at')->where('next_run_at', '<=', $now->toDateTimeString())
                ->orderBy('next_run_at')->limit(100)->pluck('id');
            foreach ($ids as $id) {
                DB::transaction(function () use ($id, $now) {
                    $a = DB::table('digital_report_assignments')->where('id', $id)->lockForUpdate()->first();
                    if (! $a || $a->status !== 'active' || ! $a->next_run_at || Clock::parse($a->next_run_at, 'UTC')->gt($now)) return;
                    if ($a->ends_at && $now->gte(Clock::parse($a->ends_at, 'UTC'))) {
                        DB::table('digital_report_assignments')->where('id', $id)->update(['status' => 'completed', 'next_run_at' => null]);
                        return;
                    }
                    // On downtime, recover only the latest due occurrence, not a backlog of old reports.
                    $slot = Clock::parse($a->next_run_at, 'UTC');
                    if ($a->frequency !== 'once') {
                        $anchor = Clock::parse($a->starts_at, 'UTC')->setTimezone($a->timezone);
                        $localNow = $now->setTimezone($a->timezone);
                        $step = (int) $a->interval_value;
                        $distance = $a->frequency === 'months'
                            ? ($localNow->year - $anchor->year) * 12 + $localNow->month - $anchor->month
                            : (int) $anchor->startOfDay()->diffInDays($localNow->startOfDay());
                        $n = max(0, intdiv(max(0, $distance), $step));
                        $candidate = $a->frequency === 'months' ? $anchor->addMonthsNoOverflow($n * $step) : $anchor->addDays($n * $step);
                        if ($candidate->gt($localNow) && $n > 0) {
                            $candidate = $a->frequency === 'months' ? $anchor->addMonthsNoOverflow(($n - 1) * $step) : $anchor->addDays(($n - 1) * $step);
                        }
                        if ($candidate->utc()->gt($slot)) $slot = $candidate->utc();
                    }
                    $employee = DigitalEmployee::query()->lockForUpdate()->find($a->digital_employee_id);
                    $owner = User::find($a->owner_id);
                    if (! $employee || ! $owner || ! $owner->isActive() || ! $owner->isPlatformOwner()
                        || (string) $employee->owner_id !== (string) $a->owner_id || $employee->status !== 'active') return;
                    $day = $now->startOfDay()->toDateTimeString();
                    $count = DB::table('digital_report_runs')->join('digital_report_assignments', 'assignment_id', '=', 'digital_report_assignments.id')
                        ->where('digital_employee_id', $employee->id)->where('digital_report_runs.created_at', '>=', $day)->count();
                    if ($count >= (int) $employee->max_daily_tasks) return;
                    if (! DB::table('digital_report_runs')->where('assignment_id', $id)->where('scheduled_at', $slot->toDateTimeString())->exists()) {
                        DB::table('digital_report_runs')->insert([
                            'assignment_id' => $id, 'scheduled_at' => $slot->toDateTimeString(), 'status' => 'queued',
                            'created_at' => $now->toDateTimeString(), 'updated_at' => $now->toDateTimeString(),
                        ]);
                    }
                    $next = app(DigitalReportSchedule::class)->next($a, $now);
                    DB::table('digital_report_assignments')->where('id', $id)->update([
                        'next_run_at' => $next?->toDateTimeString(), 'status' => $next ? 'active' : 'completed', 'updated_at' => $now->toDateTimeString(),
                    ]);
                });
            }
            $runs = DB::table('digital_report_runs')->where('status', 'queued')->orderBy('id')->limit(20)->pluck('id');
            foreach ($runs as $id) $this->execute((int) $id);
        } finally {
            $lock->release();
        }
    }

    private function execute(int $id): void
    {
        $context = DB::transaction(function () use ($id) {
            $r = DB::table('digital_report_runs')->where('id', $id)->lockForUpdate()->first();
            if (! $r || $r->status !== 'queued') return null;
            $a = DB::table('digital_report_assignments')->where('id', $r->assignment_id)->first();
            if (! $a || in_array($a->status, ['paused', 'cancelled'], true)) return null;
            $employee = DigitalEmployee::find($a->digital_employee_id);
            $owner = User::find($a->owner_id);
            if (! $employee || $employee->status !== 'active' || (string) $employee->owner_id !== (string) $a->owner_id
                || ! $owner || ! $owner->isActive() || ! $owner->isPlatformOwner()) return null;
            DB::table('digital_report_runs')->where('id', $id)->update([
                'status' => 'running', 'started_at' => Clock::now('UTC')->toDateTimeString(),
                'attempts' => $r->attempts + 1, 'error_message' => null,
            ]);
            return [$a, $r];
        });
        if (! $context) return;
        [$assignment, $run] = $context;
        try {
            $snapshot = app(DigitalReportSnapshot::class)->build($assignment, $run);
            $now = Clock::now('UTC');
            DB::table('digital_report_runs')->where('id', $id)->where('status', 'running')->where('attempts', $run->attempts + 1)->update([
                'status' => 'waiting_approval', 'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'finished_at' => $now->toDateTimeString(), 'updated_at' => $now->toDateTimeString(),
                'reminder_at' => $now->addHours((int) $assignment->reminder_hours)->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            DB::table('digital_report_runs')->where('id', $id)->where('status', 'running')->where('attempts', $run->attempts + 1)->update([
                'status' => 'failed', 'error_message' => 'تعذر استخراج التقرير. راجع سجل Laravel.',
                'finished_at' => Clock::now('UTC')->toDateTimeString(),
            ]);
        }
    }
}
