<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Driver;
use App\Models\Order;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleDashboardController extends Controller
{
    public function driver(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        abort_unless(
            $profile->driver_id !== null,
            403,
            'هذا الحساب غير مرتبط بمندوب.',
        );

        $driver = Driver::query()->findOrFail((int) $profile->driver_id);
        $metadata = is_array($driver->metadata) ? $driver->metadata : [];
        $orders = Order::query()->where('driver_id', $driver->id);
        $wallet = $this->wallet(Driver::class, $driver->id);

        $activeStatuses = [
            Order::STATUS_ASSIGNED,
            Order::STATUS_PICKED_UP,
            Order::STATUS_DELIVERING,
        ];

        $currentTask = (clone $orders)
            ->whereIn('status', $activeStatuses)
            ->with('store:id,name_ar,name_en')
            ->latest()
            ->first();

        return response()->json([
            'data' => [
                'wallet_balance' => (float) (
                    $wallet?->available_balance
                    ?? $metadata['wallet_balance']
                    ?? 0
                ),
                'pending_balance' => (float) (
                    $wallet?->pending_balance ?? 0
                ),
                'today_earnings' => $this->todayWalletCredits($wallet),
                'active_tasks_count' => (clone $orders)
                    ->whereIn('status', $activeStatuses)
                    ->count(),
                'today_deliveries_count' => (clone $orders)
                    ->whereIn('status', Order::completedStatuses())
                    ->whereDate('delivered_at', today())
                    ->count(),
                'rating' => (float) $driver->rating,
                'weekly_earnings' => $this->weeklyWalletCredits($wallet),
                'next_settlement_at' =>
                    $metadata['next_settlement_at'] ?? null,
                'current_task' => $currentTask
                    ? $this->driverTaskPayload($currentTask)
                    : null,
            ],
        ]);
    }

    public function family(Request $request): JsonResponse
    {
        [$family, $store] = $this->familyAndStore($request);
        $metadata = is_array($family->metadata) ? $family->metadata : [];
        $orders = Order::query()->where('store_id', $store->id);
        $wallet = $this->wallet(ProductiveFamily::class, $family->id);

        $latestOrder = (clone $orders)
            ->whereIn('status', Order::runningStatuses())
            ->with('items:id,order_id,product_id,product_name,quantity,unit_price,total,options')
            ->latest()
            ->first();

        return response()->json([
            'data' => [
                'store_id' => $store->id,
                'store_name' => $store->name_ar,
                'is_open' => (bool) $store->is_open,
                'rating' => (float) $store->rating,
                'new_orders_count' => (clone $orders)
                    ->where('status', Order::STATUS_PENDING)
                    ->count(),
                'preparing_orders_count' => (clone $orders)
                    ->whereIn('status', [
                        Order::STATUS_ACCEPTED,
                        Order::STATUS_PREPARING,
                        Order::STATUS_READY,
                        Order::STATUS_ASSIGNED,
                    ])
                    ->count(),
                'today_sales' => (float) (clone $orders)
                    ->where('payment_status', Order::PAYMENT_PAID)
                    ->whereIn('status', Order::completedStatuses())
                    ->whereDate('delivered_at', today())
                    ->sum('total'),
                'wallet_balance' => (float) (
                    $wallet?->available_balance
                    ?? $metadata['wallet_balance']
                    ?? 0
                ),
                'pending_balance' => (float) (
                    $wallet?->pending_balance ?? 0
                ),
                'weekly_sales' => $this->weeklyOrderTotals(
                    $orders,
                    'total',
                ),
                'next_settlement_at' =>
                    $metadata['next_settlement_at'] ?? null,
                'latest_order' => $latestOrder
                    ? $this->familyOrderPayload($latestOrder)
                    : null,
            ],
        ]);
    }

    public function familyAvailability(Request $request): JsonResponse
    {
        [, $store] = $this->familyAndStore($request);

        $data = $request->validate([
            'is_open' => ['required', 'boolean'],
        ]);

        $isOpen = (bool) $data['is_open'];

        if (
            $isOpen
            && ! in_array($store->status, ['active', 'approved'], true)
        ) {
            return response()->json([
                'message' => 'لا يمكن فتح متجر غير نشط أو غير معتمد.',
            ], 422);
        }

        $store->update(['is_open' => $isOpen]);

        return response()->json([
            'message' => $isOpen
                ? 'تم فتح المتجر وأصبح متاحًا للعملاء.'
                : 'تم إيقاف استقبال الطلبات مؤقتًا.',
            'data' => [
                'store_id' => $store->id,
                'is_open' => (bool) $store->is_open,
            ],
        ]);
    }

    private function profile(Request $request): AppProfile
    {
        return AppProfile::query()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    /**
     * @return array{0: ProductiveFamily, 1: Store}
     */
    private function familyAndStore(Request $request): array
    {
        $profile = $this->profile($request);

        abort_unless(
            $profile->productive_family_id !== null,
            403,
            'هذا الحساب غير مرتبط بأسرة منتجة.',
        );

        $family = ProductiveFamily::query()
            ->with('store')
            ->findOrFail((int) $profile->productive_family_id);

        abort_unless(
            $family->store !== null,
            422,
            'لا يوجد متجر مرتبط بهذه الأسرة.',
        );

        return [$family, $family->store];
    }

    private function wallet(string $ownerType, int $ownerId): ?Wallet
    {
        return Wallet::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('currency', 'SAR')
            ->first();
    }

    private function todayWalletCredits(?Wallet $wallet): float
    {
        if (! $wallet) {
            return 0;
        }

        return (float) WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('status', 'completed')
            ->where('amount', '>', 0)
            ->whereDate('created_at', today())
            ->sum('amount');
    }

    /**
     * @return list<float>
     */
    private function weeklyWalletCredits(?Wallet $wallet): array
    {
        $totals = $this->emptyWeek();

        if (! $wallet) {
            return array_values($totals);
        }

        $transactions = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('status', 'completed')
            ->where('amount', '>', 0)
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->get(['amount', 'created_at']);

        foreach ($transactions as $transaction) {
            $date = $transaction->created_at->toDateString();

            if (array_key_exists($date, $totals)) {
                $totals[$date] += (float) $transaction->amount;
            }
        }

        return array_values($totals);
    }

    /**
     * @return list<float>
     */
    private function weeklyOrderTotals(
        Builder $orders,
        string $amountColumn,
    ): array {
        $totals = $this->emptyWeek();

        $completedOrders = (clone $orders)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->whereIn('status', Order::completedStatuses())
            ->where('delivered_at', '>=', now()->subDays(6)->startOfDay())
            ->get([$amountColumn, 'delivered_at']);

        foreach ($completedOrders as $order) {
            if (! $order->delivered_at) {
                continue;
            }

            $date = $order->delivered_at->toDateString();

            if (array_key_exists($date, $totals)) {
                $totals[$date] += (float) $order->{$amountColumn};
            }
        }

        return array_values($totals);
    }

    /**
     * @return array<string, float>
     */
    private function emptyWeek(): array
    {
        $days = [];

        for ($offset = 6; $offset >= 0; $offset--) {
            $days[now()->subDays($offset)->toDateString()] = 0.0;
        }

        return $days;
    }

    private function driverTaskPayload(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'store' => $order->store,
            'delivery_address' => $order->delivery_address,
            'delivery_distance_km' => $order->delivery_distance_km !== null
                ? (float) $order->delivery_distance_km
                : null,
            'delivery_fee' => (float) $order->delivery_fee,
        ];
    }

    private function familyOrderPayload(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'total' => (float) $order->total,
            'items' => $order->items,
            'created_at' => $order->created_at,
        ];
    }
}
