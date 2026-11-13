<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderCouponUsage;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderCouponController extends Controller
{
    public function __construct(private readonly CouponService $coupons)
    {
    }

    public function apply(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        abort_if($order->isPaid(), 422, 'لا يمكن تعديل الخصم بعد إتمام الدفع.');
        abort_if($order->isCancelled(), 422, 'لا يمكن تطبيق كوبون على طلب ملغي أو مرفوض.');

        $data = $request->validate([
            'coupon_code' => ['required', 'string', 'max:80'],
        ]);

        $result = DB::transaction(function () use ($order, $data): array {
            // Lock the order before evaluating any mutable amounts.
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->isPaid() || $locked->isCancelled() || $locked->isSponsoredFunded()) {
                throw ValidationException::withMessages([
                    'coupon_code' => ['لا يمكن تعديل كوبون طلب مدفوع أو ملغي.'],
                ]);
            }

            // ZAD_UNIFIED_SPONSORED_COUPON_V1
            $plainCode = strtoupper(trim((string) $data['coupon_code']));
            $sponsored = \App\Models\SponsoredCreatorCoupon::query()
                ->where('code_hash', hash('sha256', $plainCode))
                ->first();

            if ($sponsored !== null) {
                if (DB::table('zad_payment_attempts')
                    ->where('order_id', $locked->id)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'coupon_code' => ['لا يمكن تطبيق كوبون ممول بعد بدء الدفع.'],
                    ]);
                }

                if ((float) $locked->discount !== 0.0) {
                    throw ValidationException::withMessages([
                        'coupon_code' => ['أزل الخصم الحالي قبل تطبيق الكوبون الممول.'],
                    ]);
                }

                $items = $locked->items()
                    ->get(['product_id', 'quantity'])
                    ->map(fn ($item) => [
                        'product_id' => (int) $item->product_id,
                        'quantity' => (int) $item->quantity,
                    ])
                    ->all();

                $cents = static fn ($value): int =>
                    (int) round((float) $value * 100);

                app(\App\Services\SponsoredCouponRedemptionService::class)
                    ->redeem(
                        $plainCode,
                        $locked,
                        $items,
                        $cents($locked->subtotal),
                        $cents($locked->delivery_fee),
                        $cents($locked->tax),
                        'coupon-apply-order-' . $locked->id
                    );

                $fundedOrder = $locked->fresh(['items', 'store']);

                return [
                    $fundedOrder,
                    [
                        'code' => $plainCode,
                        'discount_scope' => 'all',
                        'zad_share_percent' => 100,
                        'family_share_percent' => 0,
                        'driver_share_percent' => 0,
                        'sponsored_funded' => true,
                    ],
                    round(
                        (float) $fundedOrder->subtotal
                        + (float) $fundedOrder->delivery_fee
                        + (float) $fundedOrder->tax,
                        2
                    ),
                ];
            }

        $gatewayPaymentStarted = DB::table('zad_payment_attempts')
            ->where('order_id', $locked->id)
            ->where(function ($attemptQuery) {
                $attemptQuery->whereNotNull('provider_payment_id')
                    ->orWhere(function ($intentQuery) {
                        $intentQuery->where('status', 'created')
                            ->whereNotNull('payload');
                    });
            })
            ->exists();

        if ($gatewayPaymentStarted) {
            throw ValidationException::withMessages([
                'coupon_code' => ['لا يمكن تعديل الخصم بعد بدء الدفع.'],
            ]);
        }
            // CouponService supports a legacy payload-code fallback. Lock all
            // coupon rows in deterministic order so BOTH lookup paths are
            // serialized before checking usage limits. Conservative by design.
            \App\Models\PlatformRecord::query()
                ->where('resource', 'coupons-offers')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $evaluation = $this->coupons->evaluate(
                (string) $data['coupon_code'],
                (float) $locked->subtotal,
                (float) $locked->delivery_fee,
                $locked->customer_id !== null ? (int) $locked->customer_id : null,
                (int) $locked->id,
                (float) $locked->tax,
            );
            $discount = round(max(0, (float) $evaluation['discount_amount']), 2);

            $locked->update([
                'discount' => $discount,
                'total' => round(max(
                    0,
                    (float) $locked->subtotal
                    + (float) $locked->delivery_fee
                    + (float) $locked->tax
                    - $discount
                ), 2),
                'payment_status' => Order::PAYMENT_UNPAID,
            ]);

            OrderCouponUsage::query()->updateOrCreate(
                ['order_id' => $locked->id],
                [
                    'customer_id' => $locked->customer_id,
                    'platform_record_id' => $evaluation['record']->id ?? null,
                    'coupon_code' => (string) $evaluation['code'],
                    'discount_type' => (string) $evaluation['discount_type'],
                    'discount_value' => (float) $evaluation['discount_value'],
                    'max_discount' => (float) $evaluation['max_discount'],
                    'minimum_order' => (float) $evaluation['minimum_order'],
                    'subtotal_snapshot' => (float) $locked->subtotal,
                    'delivery_fee_snapshot' => (float) $locked->delivery_fee,
                    'discount_amount' => $discount,
                    'status' => 'applied',
                    'coupon_snapshot' => (array) $evaluation['snapshot'],
                ],
            );

            return [$locked->fresh(['items', 'store']), $evaluation, $discount];
        });

        [$order, $evaluation, $discount] = $result;

        return response()->json([
            'message' => 'تم تطبيق كود الخصم بنجاح.',
            'data' => [
                'coupon' => [
                    'code' => (string) $evaluation['code'],
                    'discount_amount' => $discount,
                    'discount_scope' => $evaluation['discount_scope'] ?? 'delivery',
                    'zad_share_percent' => $evaluation['zad_share_percent'] ?? 100,
                    'family_share_percent' => $evaluation['family_share_percent'] ?? 0,
                    'driver_share_percent' => $evaluation['driver_share_percent'] ?? 0,
                ],
                'order' => $this->summary($order),
                'sponsored_funded' => (bool) ($evaluation['sponsored_funded'] ?? false),
                'amount_due' => ($evaluation['sponsored_funded'] ?? false)
                    ? 0
                    : (float) $order->total,
            ],
        ]);
    }

    public function remove(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        abort_if($order->isPaid(), 422, 'لا يمكن إزالة الخصم بعد إتمام الدفع.');

        $order = DB::transaction(function () use ($order): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->isPaid() || $locked->isCancelled() || $locked->isSponsoredFunded()) {
                throw ValidationException::withMessages([
                    'coupon_code' => ['لا يمكن إزالة خصم طلب مدفوع أو ملغي.'],
                ]);
            }

            $locked->update([
                'discount' => 0,
                'total' => round(
                    (float) $locked->subtotal
                    + (float) $locked->delivery_fee
                    + (float) $locked->tax,
                    2
                ),
                'payment_status' => Order::PAYMENT_UNPAID,
            ]);

            OrderCouponUsage::query()
                ->where('order_id', $locked->id)
                ->where('status', 'applied')
                ->update(['status' => 'removed']);

            return $locked->fresh(['items', 'store']);
        });

        return response()->json([
            'message' => 'تمت إزالة كود الخصم.',
            'data' => [
                'coupon' => null,
                'order' => $this->summary($order),
                'sponsored_funded' => (bool) ($evaluation['sponsored_funded'] ?? false),
                'amount_due' => ($evaluation['sponsored_funded'] ?? false)
                    ? 0
                    : (float) $order->total,
            ],
        ]);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $guestSessionId = trim((string) $request->header('X-Guest-Session', ''));

        $guestAllowed = $guestSessionId !== ''
            && hash_equals((string) $order->guest_session_id, $guestSessionId);

        $user = $request->user();
        $customerAllowed = $user !== null
            && $order->customer_id !== null
            && $order->customer_id === $user->appProfile?->customer_id;

        abort_unless(
            $guestAllowed || $customerAllowed,
            403,
            'لا يمكنك تعديل كوبون هذا الطلب.',
        );
    }

    private function summary(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'payment_status' => $order->payment_status,
            'subtotal' => (float) $order->subtotal,
            'delivery_fee' => (float) $order->delivery_fee,
            'discount' => (float) $order->discount,
            'tax' => (float) $order->tax,
            'total' => (float) $order->total,
            'contact_phone' => $order->contact_phone,
        ];
    }
}