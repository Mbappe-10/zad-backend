<?php

namespace App\Console\Commands;

use App\Models\OrderSettlement;
use App\Services\OrderSettlementService;
use Illuminate\Console\Command;

class ReleaseDueOrderSettlements extends Command
{
    protected $signature = 'order-settlement:release-due {--limit=200}';

    protected $description = 'تحرير تسويات الطلبات التي حان موعدها';

    public function handle(OrderSettlementService $service): int
    {
        if (! $service->automaticReleaseEnabled()) {
            $this->info('Automatic settlement release is disabled.');

            return self::SUCCESS;
        }

        $limit = max(min((int) $this->option('limit'), 1000), 1);
        $released = 0;
        $failed = 0;

        OrderSettlement::query()
            ->where('status', OrderSettlement::STATUS_PENDING)
            ->whereNotNull('release_due_at')
            ->where('release_due_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (OrderSettlement $settlement) use ($service, &$released, &$failed): void {
                try {
                    $service->release($settlement);
                    $released++;
                } catch (\Throwable $exception) {
                    report($exception);
                    $failed++;
                }
            });

        $this->info("Released: {$released}; failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
