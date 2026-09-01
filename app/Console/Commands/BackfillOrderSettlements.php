<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderSettlementService;
use Illuminate\Console\Command;

class BackfillOrderSettlements extends Command
{
    protected $signature = 'order-settlement:backfill {--limit=500}';

    protected $description = 'إنشاء تسويات للطلبات المسلمة القديمة التي لا تملك سجل تسوية';

    public function handle(OrderSettlementService $service): int
    {
        $limit = max(min((int) $this->option('limit'), 5000), 1);
        $created = 0;
        $failed = 0;

        Order::query()
            ->whereIn('status', Order::completedStatuses())
            ->whereDoesntHave('settlement')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Order $order) use ($service, &$created, &$failed): void {
                try {
                    $service->prepare($order);
                    $created++;
                } catch (\Throwable $exception) {
                    report($exception);
                    $failed++;
                }
            });

        $this->info("Created: {$created}; failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
