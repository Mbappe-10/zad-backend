<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SponsoredCreatorCoupon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SponsoredCouponRedemptionService
{
    public function redeem(
        string $plainCode,
        Order $order,
        array $items,
        int $productsCents,
        int $deliveryCents,
        int $taxCents,
        string $idempotencyKey
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException("Sponsored redemption requires an outer order transaction.");
        }

        if (!$order->exists || !$order->getKey()) {
            throw new \LogicException("Order must be persisted before redemption.");
        }

        if (trim($idempotencyKey) === "" || strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages(["idempotency_key" => "Invalid idempotency key."]);
        }

        $grossCents = $productsCents + $deliveryCents + $taxCents;
        if ($productsCents < 0 || $deliveryCents < 0 || $taxCents < 0 || $grossCents <= 0) {
            throw ValidationException::withMessages(["amount" => "Invalid sponsored amount."]);
        }

        // ZAD_SPONSORED_ORDER_AMOUNT_GUARD
        $stored = Order::query()->lockForUpdate()->findOrFail($order->id);
        if ($stored->payment_status !== Order::PAYMENT_UNPAID
            || (float) $stored->discount !== 0.0
            || $stored->status !== Order::STATUS_PENDING) {
            throw ValidationException::withMessages(["order" => "Order is not eligible for sponsorship."]);
        }

        if (DB::table("zad_payment_attempts")
            ->where("order_id", $stored->id)
            ->exists()) {
            throw ValidationException::withMessages([
                "coupon" => "لا يمكن استخدام كوبون ممول بعد بدء محاولة الدفع.",
            ]);
        }
        $toCents = static fn ($value): int => (int) round((float) $value * 100);
        if ($productsCents !== $toCents($stored->subtotal)
            || $deliveryCents !== $toCents($stored->delivery_fee)
            || $taxCents !== $toCents($stored->tax)
            || $grossCents !== $toCents($stored->total)) {
            throw ValidationException::withMessages(["amount" => "Sponsored amount does not match order."]);
        }

        $expected = [];
        foreach ($stored->items()->get() as $item) {
            $id = (int) $item->product_id;
            $expected[$id] = ($expected[$id] ?? 0) + (int) $item->quantity;
        }
        $received = [];
        foreach ($items as $item) {
            if (!is_array($item)
                || !isset($item["product_id"], $item["quantity"])
                || filter_var($item["product_id"], FILTER_VALIDATE_INT) === false
                || filter_var($item["quantity"], FILTER_VALIDATE_INT) === false
                || (int) $item["quantity"] < 1) {
                throw ValidationException::withMessages(["items" => "Invalid sponsored items."]);
            }
            $id = (int) $item["product_id"];
            $received[$id] = ($received[$id] ?? 0) + (int) $item["quantity"];
        }
        ksort($expected);
        ksort($received);
        if (!$expected || $expected !== $received) {
            throw ValidationException::withMessages(["items" => "Sponsored items do not match order."]);
        }

        $coupon = SponsoredCreatorCoupon::query()
            ->where("code_hash", hash("sha256", strtoupper(trim($plainCode))))
            ->lockForUpdate()
            ->first();

        if (!$coupon) {
            throw ValidationException::withMessages(["coupon" => "Sponsored coupon unavailable."]);
        }

        app(SponsoredCouponValidationService::class)->validateCoupon(
            $plainCode, (int) $order->store_id, $items, $grossCents
        );

        if (!$coupon->isAvailable()) {
            throw ValidationException::withMessages(["coupon" => "Sponsored coupon already used."]);
        }

        $money = static fn (int $cents): string => number_format($cents / 100, 2, ".", "");

        DB::table("sponsored_order_fundings")->insert([
            "coupon_id" => $coupon->id,
            "order_id" => $order->id,
            "funding_source" => "zad_platform",
            "currency" => "SAR",
            "products_amount" => $money($productsCents),
            "delivery_amount" => $money($deliveryCents),
            "tax_amount" => $money($taxCents),
            "funded_total" => $money($grossCents),
            "status" => "committed",
            "idempotency_key" => $idempotencyKey,
            "calculation_snapshot" => json_encode([
                "items" => $items,
                "gross_cents" => $grossCents,
            ], JSON_THROW_ON_ERROR),
            "committed_at" => now(),
            "created_at" => now(),
            "updated_at" => now(),
        ]);

        $coupon->status = SponsoredCreatorCoupon::STATUS_CONSUMED;
        $coupon->consumed_at = now();
        $coupon->consumed_order_id = $order->id;
        $coupon->save();
    }
}