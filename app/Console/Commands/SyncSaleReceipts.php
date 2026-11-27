<?php

namespace App\Console\Commands;

use App\Services\SaleReceiptService;
use Illuminate\Console\Command;

class SyncSaleReceipts extends Command
{
    protected $signature = 'sale-receipts:sync {--limit=500}';

    protected $description = 'Issue sale receipts for paid orders that do not have one yet';

    public function handle(SaleReceiptService $receipts): int
    {
        $count = $receipts->syncMissing((int) $this->option('limit'));
        $this->info("Issued {$count} receipts.");

        return self::SUCCESS;
    }
}
