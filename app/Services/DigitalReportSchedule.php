<?php

namespace App\Services;

use Carbon\CarbonImmutable as Clock;

class DigitalReportSchedule
{
    // Every calculation starts from the original anchor: Jan 31 -> Feb 28 -> Mar 31.
    public function next(object $a, Clock $after): ?Clock
    {
        if ($a->frequency === 'once') {
            return null;
        }
        $start = Clock::parse($a->starts_at, 'UTC')->setTimezone($a->timezone);
        $local = $after->setTimezone($a->timezone);
        $step = (int) $a->interval_value;
        if ($a->frequency === 'months') {
            $months = ($local->year - $start->year) * 12 + $local->month - $start->month;
            $n = max(0, intdiv(max(0, $months), $step));
            $next = $start->addMonthsNoOverflow($n * $step);
            if ($next->lessThanOrEqualTo($local)) {
                $next = $start->addMonthsNoOverflow(($n + 1) * $step);
            }
        } else {
            $days = max(0, (int) $start->startOfDay()->diffInDays($local->startOfDay()));
            $n = intdiv($days, $step);
            $next = $start->addDays($n * $step);
            if ($next->lessThanOrEqualTo($local)) {
                $next = $start->addDays(($n + 1) * $step);
            }
        }
        $next = $next->utc();
        return $a->ends_at && $next->greaterThanOrEqualTo(Clock::parse($a->ends_at, 'UTC')) ? null : $next;
    }
}
