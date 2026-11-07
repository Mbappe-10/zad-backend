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