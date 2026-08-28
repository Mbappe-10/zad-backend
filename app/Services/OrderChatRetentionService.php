<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderChatPurgeLog;
use App\Models\OrderMessage;
use App\Models\PlatformSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OrderChatRetentionService
{
    public const SETTINGS_GROUP = 'communications';
    public const RETENTION_KEY = 'order_chat_retention_days';
    public const DEFAULT_RETENTION_DAYS = 11;
    public const MIN_RETENTION_DAYS = 1;
    public const MAX_RETENTION_DAYS = 365;

    public function retentionDays(): int
    {
        $setting = PlatformSetting::query()
            ->where('group', self::SETTINGS_GROUP)
            ->where('key', self::RETENTION_KEY)
            ->first();

        $value = $setting?->value;

        if (is_array($value)) {
            $value = $value['value'] ?? null;
        }

        $days = is_numeric($value)
            ? (int) $value
            : self::DEFAULT_RETENTION_DAYS;

        return max(
            self::MIN_RETENTION_DAYS,
            min(self::MAX_RETENTION_DAYS, $days),
        );
    }

    public function statistics(): array
    {
        $days = $this->retentionDays();
        $cutoff = now()->subDays($days);
        $eligibleIds = $this->eligibleOrderIds($days);

        $eligibleMessages = OrderMessage::query()
            ->whereIn('order_id', clone $eligibleIds)
            ->count();

        $eligibleSensitiveOrders = Order::query()
            ->whereIn('id', clone $eligibleIds)
            ->whereNull('sensitive_data_purged_at')
            ->count();

        return [
            'retention_days' => $days,
            'cutoff_at' => $cutoff->toISOString(),
            'eligible_orders' => $eligibleSensitiveOrders,
            'eligible_messages' => $eligibleMessages,
            'last_purge' => OrderChatPurgeLog::query()
                ->latest('id')
                ->first(),
        ];
    }

    public function purge(
        string $mode = OrderChatPurgeLog::MODE_AUTOMATIC,
        ?int $executedBy = null,
    ): array {
        return DB::transaction(function () use (
            $mode,
            $executedBy,
        ): array {
            $startedAt = now();
            $days = $this->retentionDays();
            $eligibleIds = $this->eligibleOrderIds($days);

            $deletedMessages = OrderMessage::query()
                ->whereIn('order_id', clone $eligibleIds)
                ->delete();

            /*
             * نحتفظ بسجل الطلب المالي وعناصره، وننظف فقط بيانات التواصل
             * والمواقع والملاحظات الحساسة. موقع المتجر الأساسي لا يُحذف.
             */
            $purgedOrders = Order::query()
                ->whereIn('id', clone $eligibleIds)
                ->whereNull('sensitive_data_purged_at')
                ->update([
                    'pickup_address' => null,
                    'pickup_latitude' => null,
                    'pickup_longitude' => null,
                    'delivery_address' => json_encode(
                        [],
                        JSON_THROW_ON_ERROR,
                    ),
                    'delivery_latitude' => null,
                    'delivery_longitude' => null,
                    'contact_phone' => null,
                    'notes' => null,
                    'driver_notes' => null,
                    'sensitive_data_purged_at' => now(),
                ]);

            $finishedAt = now();

            $log = OrderChatPurgeLog::query()->create([
                'mode' => $mode,
                'retention_days' => $days,
                'eligible_orders' => $purgedOrders,
                'deleted_messages' => $deletedMessages,
                'executed_by' => $executedBy,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);

            return [
                'retention_days' => $days,
                'eligible_orders' => $purgedOrders,
                'purged_orders' => $purgedOrders,
                'deleted_messages' => $deletedMessages,
                'started_at' => $startedAt->toISOString(),
                'finished_at' => $finishedAt->toISOString(),
                'log_id' => $log->id,
            ];
        });
    }

    private function eligibleOrderIds(int $days): Builder
    {
        return Order::query()
            ->select('id')
            ->whereIn('status', [
                Order::STATUS_DELIVERED,
                Order::STATUS_COMPLETED,
                Order::STATUS_CANCELLED,
                Order::STATUS_REJECTED,
            ])
            ->where('created_at', '<=', now()->subDays($days));
    }
}
