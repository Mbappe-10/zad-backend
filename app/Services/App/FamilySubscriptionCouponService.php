<?php

namespace App\Services\App;

use App\Models\FamilyStorePlan;
use App\Models\FamilyStorePurchase;
use App\Models\FamilySubscriptionCoupon;
use App\Models\FamilySubscriptionCouponRedemption;
use App\Models\ProductiveFamily;
use Illuminate\Validation\ValidationException;

/**
 * Coupons for family store plans only. Independent from order coupons:
 * separate tables, separate service, separate endpoints.
 */
class FamilySubscriptionCouponService
{
    /** Moyasar's minimum charge. A discounted price is either free or at least this. */
    public const MIN_CHARGE_HALALAS = 100;

    public static function normalize(?string $code): string
    {
        return mb_strtoupper(trim((string) $code));
    }

    /**
     * Prices a plan with an optional coupon. Never trusts a client amount.
     *
     * @return array{coupon: ?FamilySubscriptionCoupon, original_halalas: int, discount_halalas: int, final_halalas: int}
     */
    public function quote(?string $code, FamilyStorePlan $plan, ProductiveFamily $family, int $originalHalalas, bool $lock = false): array
    {
        $code = self::normalize($code);
        if ($code === '') {
            return ['coupon' => null, 'original_halalas' => $originalHalalas, 'discount_halalas' => 0, 'final_halalas' => $originalHalalas];
        }

        $query = FamilySubscriptionCoupon::query()->where('code', $code);
        $coupon = $lock ? $query->lockForUpdate()->first() : $query->first();
        $fail = fn (string $message) => throw ValidationException::withMessages(['coupon' => [$message]]);

        if (! $coupon || ! $coupon->is_active) {
            $fail('كود الخصم غير صحيح أو متوقف.');
        }
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            $fail('كود الخصم لم يبدأ بعد.');
        }
        if ($coupon->ends_at && $coupon->ends_at->isPast()) {
            $fail('انتهت صلاحية كود الخصم.');
        }
        $plans = array_values(array_filter((array) ($coupon->plan_codes ?? [])));
        if ($plans !== [] && ! in_array($plan->code, $plans, true)) {
            $fail('كود الخصم لا ينطبق على هذه الباقة.');
        }

        $used = FamilySubscriptionCouponRedemption::query()->where('coupon_id', $coupon->id)
            ->whereIn('status', ['reserved', 'redeemed']);
        if ($coupon->max_redemptions !== null && (clone $used)->count() >= $coupon->max_redemptions) {
            $fail('انتهى عدد مرات استخدام كود الخصم.');
        }
        if ($coupon->per_family_limit > 0
            && (clone $used)->where('productive_family_id', $family->id)->count() >= $coupon->per_family_limit) {
            $fail('سبق لمتجرك استخدام كود الخصم هذا.');
        }

        $value = (float) $coupon->discount_value;
        $discount = $coupon->discount_type === 'percentage'
            ? (int) round($originalHalalas * min(max($value, 0), 100) / 100)
            : (int) round(max($value, 0) * 100);
        $discount = min($discount, $originalHalalas);
        $final = $originalHalalas - $discount;
        if ($final > 0 && $final < self::MIN_CHARGE_HALALAS) {
            $final = min(self::MIN_CHARGE_HALALAS, $originalHalalas);
            $discount = $originalHalalas - $final;
        }

        return ['coupon' => $coupon, 'original_halalas' => $originalHalalas, 'discount_halalas' => $discount, 'final_halalas' => $final];
    }

    /** Holds one use while the family pays, so a limited coupon cannot be oversold. */
    public function reserve(FamilySubscriptionCoupon $coupon, FamilyStorePurchase $purchase, int $discountHalalas): void
    {
        FamilySubscriptionCouponRedemption::query()->create([
            'coupon_id' => $coupon->id,
            'purchase_id' => $purchase->id,
            'productive_family_id' => $purchase->productive_family_id,
            'discount_halalas' => $discountHalalas,
            'status' => 'reserved',
        ]);
    }

    /** Called once the payment is verified (or a 100% coupon activates the plan). */
    public function redeem(FamilyStorePurchase $purchase): void
    {
        $redemption = FamilySubscriptionCouponRedemption::query()->where('purchase_id', $purchase->id)->lockForUpdate()->first();
        if (! $redemption || $redemption->status === 'redeemed') {
            return;
        }
        $redemption->update(['status' => 'redeemed']);
        FamilySubscriptionCoupon::query()->whereKey($redemption->coupon_id)->increment('redeemed_count');
    }

    /** Frees the held use when an attempt fails or is replaced. A later verified payment can still redeem it. */
    public function release(FamilyStorePurchase $purchase): void
    {
        FamilySubscriptionCouponRedemption::query()->where('purchase_id', $purchase->id)
            ->where('status', 'reserved')->update(['status' => 'released']);
    }
}
