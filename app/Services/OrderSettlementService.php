<?php

namespace App\Services;

use App\Models\CommissionRule;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderCommission;
use App\Models\OrderSettlement;
use App\Models\OrderStatusHistory;
use App\Models\ProductiveFamily;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderSettlementService
{
    public function __construct(
        private readonly FinancialService $financial,
    ) {
    }

    public function prepare(Order $order, ?int $userId = null): OrderSettlement
    {
        $settlement = DB::transaction(function () use ($order, $userId): OrderSettlement {
            $order = Order::query()
                ->with('store:id,productive_family_id')
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (! in_array($order->status, Order::completedStatuses(), true)) {
                throw ValidationException::withMessages([
                    'status' => ['لا يمكن إنشاء تسوية قبل تسليم الطلب.'],
                ]);
            }

            $existing = OrderSettlement::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $familyId = $order->store?->productive_family_id;
            $familyGross = max(
                round((float) $order->subtotal - (float) $order->discount, 2),
                0,
            );
            $driverGross = max(round((float) $order->delivery_fee, 2), 0);

            [$familyCommission, $familyRule] = $this->commission(
                $order,
                'store',
                $familyGross,
                (float) $this->setting('settlements.family_commission_percentage', 0),
            );

            [$driverCommission, $driverRule] = $this->commission(
                $order,
                'driver',
                $driverGross,
                (float) $this->setting('settlements.driver_commission_percentage', 0),
            );

            $familyNet = max(round($familyGross - $familyCommission, 2), 0);
            $driverNet = max(round($driverGross - $driverCommission, 2), 0);
            $delayHours = max((int) $this->setting('settlements.release_delay_hours', 24), 0);
            $releaseDueAt = ($order->delivered_at ?? now())->copy()->addHours($delayHours);

            $settlement = OrderSettlement::query()->create([
                'order_id' => $order->id,
                'store_id' => $order->store_id,
                'productive_family_id' => $familyId,
                'driver_id' => $order->driver_id,
                'currency' => 'SAR',
                'order_total' => round((float) $order->total, 2),
                'family_gross' => $familyGross,
                'family_commission' => $familyCommission,
                'family_net' => $familyNet,
                'driver_gross' => $driverGross,
                'driver_commission' => $driverCommission,
                'driver_net' => $driverNet,
                'platform_total' => round($familyCommission + $driverCommission, 2),
                'status' => OrderSettlement::STATUS_PENDING,
                'release_due_at' => $releaseDueAt,
                'calculation_snapshot' => [
                    'prepared_by' => $userId,
                    'prepared_at' => now()->toISOString(),
                    'family_rule_id' => $familyRule?->id,
                    'driver_rule_id' => $driverRule?->id,
                    'family_base' => $familyGross,
                    'driver_base' => $driverGross,
                    'release_delay_hours' => $delayHours,
                ],
            ]);

            if ($familyId !== null && $familyNet > 0) {
                $familyWallet = $this->wallet(ProductiveFamily::class, (int) $familyId);
                $transaction = $this->financial->holdCredit(
                    $familyWallet,
                    $familyNet,
                    "SET-{$order->id}-FAMILY",
                    'order_settlement_family',
                    "مستحقات الأسرة للطلب {$order->number}",
                    $settlement,
                    $userId,
                );
                $settlement->family_wallet_transaction_id = $transaction->id;
            }

            if ($order->driver_id !== null && $driverNet > 0) {
                $driverWallet = $this->wallet(Driver::class, (int) $order->driver_id);
                $transaction = $this->financial->holdCredit(
                    $driverWallet,
                    $driverNet,
                    "SET-{$order->id}-DRIVER",
                    'order_settlement_driver',
                    "ربح رحلة الطلب {$order->number}",
                    $settlement,
                    $userId,
                );
                $settlement->driver_wallet_transaction_id = $transaction->id;
            }

            $settlement->save();

            $this->storeCommission(
                $order,
                $familyRule,
                'store',
                $order->store_id,
                $familyGross,
                $familyCommission,
            );

            $this->storeCommission(
                $order,
                $driverRule,
                'driver',
                $order->driver_id,
                $driverGross,
                $driverCommission,
            );

            return $settlement;
        });

        if (
            $this->automaticReleaseEnabled() &&
            $settlement->status === OrderSettlement::STATUS_PENDING &&
            $settlement->release_due_at?->isPast()
        ) {
            return $this->release($settlement, $userId, true);
        }

        return $settlement->fresh();
    }

    public function release(
        OrderSettlement $settlement,
        ?int $userId = null,
        bool $force = false,
    ): OrderSettlement {
        return DB::transaction(function () use ($settlement, $userId, $force): OrderSettlement {
            $settlement = OrderSettlement::query()
                ->lockForUpdate()
                ->findOrFail($settlement->id);

            if ($settlement->status === OrderSettlement::STATUS_RELEASED) {
                return $settlement;
            }

            if ($settlement->status === OrderSettlement::STATUS_REVERSED) {
                throw ValidationException::withMessages([
                    'settlement' => ['لا يمكن تحرير تسوية معكوسة.'],
                ]);
            }

            if ($settlement->status === OrderSettlement::STATUS_HELD && ! $force) {
                throw ValidationException::withMessages([
                    'settlement' => ['التسوية موقوفة وتحتاج قرارًا من الإدارة.'],
                ]);
            }

            if (! $force && $settlement->release_due_at?->isFuture()) {
                throw ValidationException::withMessages([
                    'settlement' => ['لم يحن موعد تحرير التسوية بعد.'],
                ]);
            }

            if ($settlement->family_wallet_transaction_id !== null) {
                $this->financial->releaseHeldCredit(
                    (int) $settlement->family_wallet_transaction_id,
                    $userId,
                );
            }

            if ($settlement->driver_wallet_transaction_id !== null) {
                $this->financial->releaseHeldCredit(
                    (int) $settlement->driver_wallet_transaction_id,
                    $userId,
                );
            }

            $releasedAt = now();
            $settlement->update([
                'status' => OrderSettlement::STATUS_RELEASED,
                'released_at' => $releasedAt,
                'released_by' => $userId,
                'hold_reason' => null,
            ]);

            OrderCommission::query()
                ->where('order_id', $settlement->order_id)
                ->update([
                    'status' => 'released',
                    'released_at' => $releasedAt,
                ]);

            $order = Order::query()->lockForUpdate()->find($settlement->order_id);

            if ($order !== null && $order->status === Order::STATUS_DELIVERED) {
                $order->update(['status' => Order::STATUS_COMPLETED]);

                OrderStatusHistory::query()->create([
                    'order_id' => $order->id,
                    'from_status' => Order::STATUS_DELIVERED,
                    'to_status' => Order::STATUS_COMPLETED,
                    'note' => 'اكتملت التسوية المالية للطلب.',
                    'changed_by' => $userId,
                ]);
            }

            return $settlement->fresh();
        });
    }

    public function hold(OrderSettlement $settlement, string $reason): OrderSettlement
    {
        return DB::transaction(function () use ($settlement, $reason): OrderSettlement {
            $settlement = OrderSettlement::query()
                ->lockForUpdate()
                ->findOrFail($settlement->id);

            if ($settlement->status === OrderSettlement::STATUS_RELEASED) {
                throw ValidationException::withMessages([
                    'settlement' => ['لا يمكن إيقاف تسوية تم تحريرها.'],
                ]);
            }

            $settlement->update([
                'status' => OrderSettlement::STATUS_HELD,
                'hold_reason' => trim($reason),
            ]);

            return $settlement->fresh();
        });
    }

    public function automaticReleaseEnabled(): bool
    {
        return (bool) $this->setting('settlements.automatic_release_enabled', true);
    }

    private function wallet(string $ownerType, int $ownerId): Wallet
    {
        return Wallet::query()->firstOrCreate(
            [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'currency' => 'SAR',
            ],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'is_frozen' => false,
            ],
        );
    }

    /** @return array{0: float, 1: CommissionRule|null} */
    private function commission(
        Order $order,
        string $beneficiaryType,
        float $base,
        float $fallbackPercentage,
    ): array {
        if ($base <= 0) {
            return [0.0, null];
        }

        $now = now();
        $rule = CommissionRule::query()
            ->where('beneficiary_type', $beneficiaryType)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->where(fn ($query) => $query->whereNull('city_id')->orWhere('city_id', $order->city_id))
            ->where(fn ($query) => $query->whereNull('store_id')->orWhere('store_id', $order->store_id))
            ->where(fn ($query) => $query->whereNull('vehicle_type')->orWhere('vehicle_type', $order->assigned_vehicle_type))
            ->orderBy('priority')
            ->first();

        $amount = $rule === null
            ? $base * max(min($fallbackPercentage, 100), 0) / 100
            : ($rule->calculation_type === 'fixed'
                ? (float) $rule->value
                : $base * (float) $rule->value / 100);

        if ($rule?->minimum_amount !== null) {
            $amount = max($amount, (float) $rule->minimum_amount);
        }

        if ($rule?->maximum_amount !== null) {
            $amount = min($amount, (float) $rule->maximum_amount);
        }

        return [min(round(max($amount, 0), 2), $base), $rule];
    }

    private function storeCommission(
        Order $order,
        ?CommissionRule $rule,
        string $type,
        ?int $beneficiaryId,
        float $base,
        float $commission,
    ): void {
        if ($beneficiaryId === null) {
            return;
        }

        OrderCommission::query()->updateOrCreate(
            [
                'order_id' => $order->id,
                'beneficiary_type' => $type,
                'beneficiary_id' => $beneficiaryId,
            ],
            [
                'commission_rule_id' => $rule?->id,
                'base_amount' => $base,
                'commission_amount' => $commission,
                'status' => 'pending',
                'released_at' => null,
            ],
        );
    }

    private function setting(string $key, mixed $fallback): mixed
    {
        $raw = DB::table('app_settings')->where('key', $key)->value('value');

        if ($raw === null) {
            return $fallback;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
        }

        return $raw;
    }
}
