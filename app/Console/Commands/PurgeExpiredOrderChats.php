<?php

namespace App\Console\Commands;

use App\Models\OrderChatPurgeLog;
use App\Services\OrderChatRetentionService;
use Illuminate\Console\Command;

class PurgeExpiredOrderChats extends Command
{
    protected $signature = 'order-chat:purge-expired';

    protected $description =
        'حذف رسائل الطلبات المنتهية بعد انتهاء مدة الاحتفاظ';

    public function handle(
        OrderChatRetentionService $retention,
    ): int {
        $result = $retention->purge(
            mode: OrderChatPurgeLog::MODE_AUTOMATIC,
        );

        $this->info(
            'Deleted messages: '.
            $result['deleted_messages'].
            ' | Eligible orders: '.
            $result['eligible_orders'].
            ' | Retention days: '.
            $result['retention_days'],
        );

        return self::SUCCESS;
    }
}