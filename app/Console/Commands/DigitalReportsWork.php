<?php

namespace App\Console\Commands;

use App\Services\DigitalReportRunner;
use Illuminate\Console\Command;

class DigitalReportsWork extends Command
{
    protected $signature = 'zad:digital-reports-work {--once : Run one due-report cycle}';
    protected $description = 'Process only ZAD digital report assignments; no order or settlement mutations.';

    public function handle(DigitalReportRunner $runner): int
    {
        $this->info('ZAD digital reports worker started. Ctrl+C stops it.');
        do {
            try { $runner->tick(); }
            catch (\Throwable $e) { report($e); $this->error('Cycle failed; see Laravel log.'); }
            if ($this->option('once')) break;
            sleep(30);
        } while (true);
        return self::SUCCESS;
    }
}
