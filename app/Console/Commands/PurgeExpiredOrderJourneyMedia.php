<?php

namespace App\Console\Commands;

use App\Models\JourneyRetentionSetting;
use App\Models\Order;
use App\Services\OrderJourneyRetentionService;
use Illuminate\Console\Command;
use Throwable;

class PurgeExpiredOrderJourneyMedia extends Command
{
    protected $signature = 'journey:purge-expired
        {--order=* : أرقام الطلبات لتنفيذ محدد}
        {--force : حذف فوري للطلبات المحددة فقط}
        {--dry-run : معاينة دون حذف}';

    protected $description = 'جدولة وحذف وسائط رحلات الطلب المنتهية بطريقة آمنة';

    public function handle(OrderJourneyRetentionService $retention): int
    {
        $settings = JourneyRetentionSetting::current();
        $orderIds = collect($this->option('order'))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if (
            ! $settings->automatic_purge_enabled
            && $orderIds->isEmpty()
            && ! $this->option('dry-run')
        ) {
            $this->info('الحذف التلقائي متوقف من الإعدادات.');
            return self::SUCCESS;
        }

        Order::query()
            ->whereIn('status', [...Order::completedStatuses(), ...Order::cancelledStatuses()])
            ->whereNull('media_delete_at')
            ->where('media_retention_hold', false)
            ->chunkById(100, function ($orders) use ($retention): void {
                foreach ($orders as $order) {
                    $retention->schedule($order);
                }
            });

        $query = Order::query()
            ->whereIn('media_retention_status', ['scheduled', 'failed'])
            ->where('media_retention_hold', false)
            ->where(function ($query): void {
                $query->whereNull('media_hold_until')
                    ->orWhere('media_hold_until', '<=', now());
            });

        if ($orderIds->isNotEmpty()) {
            $query->whereIn('id', $orderIds);

            if (! $this->option('force')) {
                $query->where('media_delete_at', '<=', now());
            }
        } else {
            $query->where('media_delete_at', '<=', now());
        }

        $orders = $query
            ->orderBy('media_delete_at')
            ->limit(max(1, min(500, $settings->purge_batch_size)))
            ->get();

        if ($this->option('dry-run')) {
            $this->table(
                ['ID', 'رقم الطلب', 'الحالة', 'موعد الحذف'],
                $orders->map(fn (Order $order): array => [
                    $order->id,
                    $order->number,
                    $order->media_retention_status,
                    $order->media_delete_at,
                ]),
            );

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($orders as $order) {
            try {
                $retention->purge($order);
                $this->info("تم حذف وسائط الطلب {$order->number}.");
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->error("فشل الطلب {$order->number}: {$exception->getMessage()}");
            }
        }

        $this->info("اكتمل التنفيذ: {$orders->count()} طلب، فشل {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}