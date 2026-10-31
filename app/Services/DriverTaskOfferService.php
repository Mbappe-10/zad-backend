<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Driver-visible offers. Does not alter price, settlement or order distances. */
class DriverTaskOfferService
{
    public static function enabled(): bool
    {
        return (bool) config('zad_driver_offers.enabled', false);
    }

    /** Only orders explicitly selected for pilot receive first-accept offers. */
    public static function enabledForOrder(Order $order): bool
    {
        if (! self::enabled()) return false;
        if ((bool) config('zad_driver_offers.all_orders', false)) return true;
        return in_array((int) $order->id, (array) config('zad_driver_offers.test_order_ids', []), true);
    }

    public static function windowExpiresAt(\DateTimeInterface $readyAt, \DateTimeInterface $current, int $seconds = 20): CarbonImmutable
    {
        $seconds = max(5, min(120, $seconds));
        $start = $readyAt->getTimestamp();
        $now = $current->getTimestamp();
        $wave = intdiv(max(0, $now - $start), $seconds);
        return CarbonImmutable::createFromTimestamp($start + (($wave + 1) * $seconds), 'UTC');
    }

    private function approved(Driver $driver): bool
    {
        return (bool) $driver->is_online && in_array($driver->status, ['active', 'approved'], true)
            && ! in_array((string) $driver->application_status, ['rejected', 'suspended', 'blocked'], true);
    }

    private function eligible(Driver $driver, Order $order): bool
    {
        if (! self::enabledForOrder($order) || ! $this->approved($driver)) return false;
        if ((string) $order->status !== Order::STATUS_READY || $order->driver_id !== null) return false;
        if (! $order->isPaidAndContactVerified()) return false;
        if ($driver->city_id !== null && $order->city_id !== null && (int) $driver->city_id !== (int) $order->city_id) return false;

        // Same vehicle category when both are known; never promise an incompatible job.
        $driverVehicle = $driver->vehicle_type ?? $driver->vehicle_category ?? null;
        $neededVehicle = $order->assigned_vehicle_type ?? $order->recommended_vehicle_type ?? null;
        if ($driverVehicle && $neededVehicle && (string) $driverVehicle !== (string) $neededVehicle) return false;
        return true;
    }

    public function listFor(Driver $driver): array
    {
        if (! self::enabled() || ! $this->approved($driver)) return [];

        $pilotIds = (array) config('zad_driver_offers.test_order_ids', []);
        $allOrders = (bool) config('zad_driver_offers.all_orders', false);
        if (! $allOrders && count($pilotIds) === 0) return [];

        $orders = Order::query()
            ->where('status', Order::STATUS_READY)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->whereNull('driver_id')
            ->operationallyReleased()
            ->when(! $allOrders, fn ($query) => $query->whereIn('id', $pilotIds))
            ->when($driver->city_id, fn ($query) => $query->where('city_id', $driver->city_id))
            ->with('store:id,name_ar,name_en')
            ->oldest('ready_at')
            ->limit(30)
            ->get();

        $declinedAt = DB::table('driver_task_declines')
            ->where('driver_id', $driver->id)
            ->whereIn('order_id', $orders->pluck('id'))
            ->pluck('created_at', 'order_id');
        $seconds = (int) config('zad_driver_offers.window_seconds', 20);

        return $orders->filter(function (Order $order) use ($driver, $declinedAt, $seconds): bool {
            if (! $this->eligible($driver, $order)) return false;
            $declined = $declinedAt->get($order->id);
            if ($declined === null) return true;
            $expiry = self::windowExpiresAt(
                CarbonImmutable::parse($order->ready_at ?? $order->created_at), now(), $seconds,
            );
            return CarbonImmutable::parse($declined)->lessThan($expiry->subSeconds($seconds));
        })->map(fn (Order $order) => $this->offerPayload($order))
            ->values()->all();
    }

    public function decline(Order $order, Driver $driver): void
    {
        if (! self::enabledForOrder($order)) abort(409, 'المهمة غير متاحة للإسناد.');
        DB::transaction(function () use ($order, $driver): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $freshDriver = Driver::query()->findOrFail($driver->id);
            if (! $this->eligible($freshDriver, $lockedOrder)) abort(409, 'المهمة غير متاحة لهذا المندوب.');
            // A decline applies to THIS 20-second wave, not all future waves.
            DB::table('driver_task_declines')->updateOrInsert(
                ['order_id' => $lockedOrder->id, 'driver_id' => $freshDriver->id],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }, 3);
    }

    public function accept(Order $order, Driver $driver, DeliveryOperationsService $operations, int $userId, string $offerExpiresAt): array
    {
        if (! self::enabledForOrder($order)) abort(409, 'المهمة غير متاحة للإسناد.');

        return DB::transaction(function () use ($order, $driver, $operations, $userId, $offerExpiresAt): array {
            // The order lock serializes simultaneous acceptance by any two drivers.
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $lockedDriver = Driver::query()->findOrFail($driver->id);

            if (! $this->eligible($lockedDriver, $lockedOrder)) {
                throw ValidationException::withMessages(['order' => ['انتهت المهمة أو قبلها مندوب آخر.']]);
            }
            $expiry = self::windowExpiresAt(
                CarbonImmutable::parse($lockedOrder->ready_at ?? $lockedOrder->created_at),
                now(), (int) config('zad_driver_offers.window_seconds', 20)
            );
            $submitted = strtotime($offerExpiresAt);
            if ($submitted === false || $submitted !== $expiry->getTimestamp()) {
                throw ValidationException::withMessages(['order' => ['انتهت مهلة القبول. حدّث المهمات المتاحة.']]);
            }
            $waveStart = $expiry->subSeconds((int) config('zad_driver_offers.window_seconds', 20));
            if (DB::table('driver_task_declines')->where('order_id', $lockedOrder->id)
                    ->where('driver_id', $lockedDriver->id)
                    ->where('created_at', '>=', $waveStart)->exists()) {
                throw ValidationException::withMessages(['order' => ['رفضت هذا العرض في الجولة الحالية.']]);
            }

            $operations->assign($lockedOrder, $lockedDriver, $userId);
            return ['order_id' => $lockedOrder->id, 'status' => 'assigned'];
        }, 3);
    }

    private function offerPayload(Order $order): array
    {
        // Official distance calculated and stored when order was created.
        // NEVER use the driver's GPS distance in the offer.
        $distanceKm = $order->delivery_distance_km;
        $payout = null;
        foreach (['driver_net_earning', 'driver_earning', 'driver_payout', 'driver_fee'] as $field) {
            $value = $order->getAttribute($field);
            if ($value !== null) { $payout = (float) $value; break; }
        }
        $seconds = (int) config('zad_driver_offers.window_seconds', 20);
        $ready = CarbonImmutable::parse($order->ready_at ?? $order->created_at);
        return [
            'id' => (int) $order->id,
            'number' => $order->number,
            'store_name' => $order->store?->name_ar ?: ($order->store?->name_en ?: 'أسرة منتجة'),
            'delivery_distance_km' => $distanceKm === null ? null : (float) $distanceKm,
            'driver_earning' => $payout, // null means no verified net earning in order.
            'delivery_fee' => (float) $order->delivery_fee, // gross customer delivery fee, NOT net pay.
            'vehicle_type' => $order->assigned_vehicle_type ?? $order->recommended_vehicle_type,
            'expires_at' => self::windowExpiresAt($ready, now(), $seconds)->toIso8601String(),
        ];
    }
}
