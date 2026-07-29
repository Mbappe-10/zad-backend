<?php

namespace App\Services;

use App\Models\DeliveryAssignment;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryOperationsService
{
    private const TRANSITIONS = [
        'pending' => ['accepted', 'cancelled'],
        'accepted' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['picked_up', 'cancelled'],
        'picked_up' => ['delivering', 'cancelled'],
        'delivering' => ['delivered', 'cancelled'],
        'delivered' => [], 'cancelled' => [],
    ];

    public function transition(Order $order, string $status, ?string $note, ?int $userId): Order
    {
        $allowed = self::TRANSITIONS[$order->status] ?? [];
        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'الانتقال بين حالتي الطلب غير مسموح.']);
        }

        return DB::transaction(function () use ($order, $status, $note, $userId) {
            $from = $order->status;
            $updates = ['status' => $status];
            $column = match ($status) {
                'accepted' => 'accepted_at','preparing' => 'preparing_at','ready' => 'ready_at','picked_up' => 'picked_up_at','delivered' => 'delivered_at','cancelled' => 'cancelled_at',default => null
            };
            if ($column) {
                $updates[$column] = now();
            }
            $order->update($updates);
            OrderStatusHistory::create(['order_id' => $order->id, 'from_status' => $from, 'to_status' => $status, 'note' => $note, 'changed_by' => $userId]);
            if (in_array($status, ['delivered', 'cancelled'], true) && $order->driver_id) {
                Driver::whereKey($order->driver_id)->where('active_orders_count', '>', 0)->decrement('active_orders_count');
            }

            return $order->fresh();
        });
    }

    public function assign(Order $order, Driver $driver, ?int $userId, bool $force = false): DeliveryAssignment
    {
        if (! $force) {
            if (! $driver->is_online || ! in_array($driver->status, ['active', 'approved'], true)) {
                throw ValidationException::withMessages(['driver_id' => 'المندوب غير متاح حاليًا.']);
            }
            if ($order->city_id && $driver->city_id && $order->city_id !== $driver->city_id) {
                throw ValidationException::withMessages(['driver_id' => 'المندوب خارج مدينة الطلب.']);
            }
        }

        return DB::transaction(function () use ($order, $driver, $userId) {
            if ($order->driver_id && $order->driver_id !== $driver->id) {
                Driver::whereKey($order->driver_id)->where('active_orders_count', '>', 0)->decrement('active_orders_count');
            }
            DeliveryAssignment::where('order_id', $order->id)->whereIn('status', ['offered', 'accepted'])->update(['status' => 'reassigned', 'responded_at' => now()]);
            $assignment = DeliveryAssignment::create(['order_id' => $order->id, 'driver_id' => $driver->id, 'status' => 'accepted', 'score' => 0, 'offered_at' => now(), 'responded_at' => now(), 'assigned_by' => $userId]);
            $order->update(['driver_id' => $driver->id]);
            $driver->increment('active_orders_count');
            OrderStatusHistory::create(['order_id' => $order->id, 'from_status' => $order->status, 'to_status' => $order->status, 'note' => 'تم إسناد الطلب إلى المندوب '.$driver->name, 'changed_by' => $userId]);

            return $assignment;
        });
    }

    public function autoAssign(Order $order, ?int $userId): DeliveryAssignment
    {
        $driver = Driver::query()->whereIn('status', ['active', 'approved'])->where('is_online', true)
            ->when($order->city_id, fn ($q) => $q->where('city_id', $order->city_id))
            ->orderBy('active_orders_count')->orderByDesc('rating')->first();
        if (! $driver) {
            throw ValidationException::withMessages(['driver_id' => 'لا يوجد مندوب متاح حاليًا.']);
        }

        return $this->assign($order,$driver,$userId);
    }
}
