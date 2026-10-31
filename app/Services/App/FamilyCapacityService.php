<?php

namespace App\Services\App;

use App\Models\AppSetting;
use App\Models\Order;
use App\Models\Store;

class FamilyCapacityService
{
    public const ENABLED_KEY = 'features.capacity_management_enabled';
    public const DEFAULT_LIMIT_KEY = 'capacity.default_max_active_orders';

    public function enabled(): bool
    {
        return (bool) (
            AppSetting::query()
                ->where('key', self::ENABLED_KEY)
                ->value('value')
            ?? false
        );
    }

    public function defaultLimit(): int
    {
        $value = AppSetting::query()
            ->where('key', self::DEFAULT_LIMIT_KEY)
            ->value('value');

        return max(1, min(100, (int) ($value ?? 5)));
    }

    public function activeOrders(Store $store): int
    {
        return Order::query()
            ->where('store_id', $store->id)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->whereIn('status', Order::runningStatuses())
            ->count();
    }

    public function snapshot(Store $store): array
    {
        $enabled = $this->enabled();
        $limit = $this->defaultLimit();
        $active = $this->activeOrders($store);

        return [
            'enabled' => $enabled,
            'active_orders' => $active,
            'max_active_orders' => $limit,
            'remaining_capacity' => max(0, $limit - $active),
            'at_capacity' => $enabled && $active >= $limit,
        ];
    }

    public function canAccept(Store $store): bool
    {
        return ! $this->snapshot($store)['at_capacity'];
    }
}
