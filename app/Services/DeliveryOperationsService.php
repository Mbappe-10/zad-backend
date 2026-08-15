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
        'accepted' => ['preparing', 'ready', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['assigned', 'picked_up', 'cancelled'],
        'assigned' => ['picked_up', 'cancelled'],
        'picked_up' => ['delivering', 'cancelled'],
        'delivering' => ['delivered', 'cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function transition(
        Order $order,
        string $status,
        ?string $note,
        ?int $userId,
    ): Order {
        $allowed = self::TRANSITIONS[$order->status] ?? [];

        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ['الانتقال بين حالتي الطلب غير مسموح.'],
            ]);
        }

        return DB::transaction(function () use (
            $order,
            $status,
            $note,
            $userId,
        ): Order {
            $from = $order->status;
            $updates = ['status' => $status];

            $column = match ($status) {
                Order::STATUS_ACCEPTED => 'accepted_at',
                Order::STATUS_PREPARING => 'preparing_at',
                Order::STATUS_READY => 'ready_at',
                Order::STATUS_PICKED_UP => 'picked_up_at',
                Order::STATUS_DELIVERED => 'delivered_at',
                Order::STATUS_CANCELLED => 'cancelled_at',
                default => null,
            };

            if ($column !== null) {
                $updates[$column] = now();
            }

            $order->update($updates);

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'from_status' => $from,
                'to_status' => $status,
                'note' => $note,
                'changed_by' => $userId,
            ]);

            if (
                in_array($status, [
                    Order::STATUS_DELIVERED,
                    Order::STATUS_CANCELLED,
                ], true) &&
                $order->driver_id
            ) {
                Driver::query()
                    ->whereKey($order->driver_id)
                    ->where('active_orders_count', '>', 0)
                    ->decrement('active_orders_count');
            }

            return $order->fresh();
        });
    }

    public function assign(
        Order $order,
        Driver $driver,
        ?int $userId,
        bool $force = false,
    ): DeliveryAssignment {
        if (! $force) {
            if (
                ! $driver->is_online ||
                ! in_array($driver->status, ['active', 'approved'], true)
            ) {
                throw ValidationException::withMessages([
                    'driver_id' => ['المندوب غير متاح حاليًا.'],
                ]);
            }

            if (
                $order->city_id &&
                $driver->city_id &&
                $order->city_id !== $driver->city_id
            ) {
                throw ValidationException::withMessages([
                    'driver_id' => ['المندوب خارج مدينة الطلب.'],
                ]);
            }
        }

        return DB::transaction(function () use (
            $order,
            $driver,
            $userId,
        ): DeliveryAssignment {
            $order->refresh();
            $fromStatus = $order->status;

            if ($order->driver_id && $order->driver_id !== $driver->id) {
                Driver::query()
                    ->whereKey($order->driver_id)
                    ->where('active_orders_count', '>', 0)
                    ->decrement('active_orders_count');
            }

            DeliveryAssignment::query()
                ->where('order_id', $order->id)
                ->whereIn('status', ['offered', 'accepted'])
                ->update([
                    'status' => 'reassigned',
                    'responded_at' => now(),
                ]);

            $assignment = DeliveryAssignment::query()->create([
                'order_id' => $order->id,
                'driver_id' => $driver->id,
                'status' => 'accepted',
                'score' => 0,
                'offered_at' => now(),
                'responded_at' => now(),
                'assigned_by' => $userId,
            ]);

            $nextStatus = $fromStatus === Order::STATUS_READY
                ? Order::STATUS_ASSIGNED
                : $fromStatus;

            $order->update([
                'driver_id' => $driver->id,
                'status' => $nextStatus,
            ]);

            if ($order->wasChanged('driver_id')) {
                $driver->increment('active_orders_count');
            }

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'from_status' => $fromStatus,
                'to_status' => $nextStatus,
                'note' => 'تم إسناد الطلب إلى المندوب '.$driver->name,
                'changed_by' => $userId,
            ]);

            return $assignment;
        });
    }

    public function autoAssign(
        Order $order,
        ?int $userId,
    ): DeliveryAssignment {
        $driver = Driver::query()
            ->whereIn('status', ['active', 'approved'])
            ->where('is_online', true)
            ->when(
                $order->city_id,
                fn ($query) => $query->where('city_id', $order->city_id),
            )
            ->orderBy('active_orders_count')
            ->orderByDesc('rating')
            ->first();

        if ($driver === null) {
            throw ValidationException::withMessages([
                'driver_id' => ['لا يوجد مندوب متاح حاليًا.'],
            ]);
        }

        return $this->assign($order, $driver, $userId);
    }
}