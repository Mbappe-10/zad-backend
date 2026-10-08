<?php

namespace App\Services;

use App\Models\LaunchCampaignCreator;
use App\Models\OrderCouponUsage;
use App\Models\PlatformRecord;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class CouponService
{
    /**
     * Validate a coupon and calculate its discount.
     *
     * Important:
     * - Laravel is the source of truth.
     * - The client sends only the coupon code.
     * - Current executable discount types: percentage and fixed.
     */
    public function calculateDiscount(
        string $discountType,
        float $value,
        float $subtotal,
        float $maxDiscount = 0
    ): float {
        $discountType = strtolower(trim($discountType));
        $value = max(0, $value);
        $subtotal = max(0, $subtotal);
        $maxDiscount = max(0, $maxDiscount);

        $discount = match ($discountType) {
            'percentage' => $subtotal * min($value, 100) / 100,
            'fixed' => $value,
            default => throw ValidationException::withMessages([
                'coupon_code' => ['نوع هذا العرض غير مدعوم في الدفع حاليًا.'],
            ]),
        };

        if ($maxDiscount > 0) {
            $discount = min($discount, $maxDiscount);
        }

        return round(
            min(max(0, $discount), $subtotal),
            2
        );
    }

    public function evaluate(
        string $code,
        float $subtotal,
        float $deliveryFee = 0,
        ?int $customerId = null,
        ?int $currentOrderId = null
    ): array {
        $normalizedCode = strtoupper(trim($code));

        if ($normalizedCode === '') {
            throw ValidationException::withMessages([
                'coupon_code' => ['رمز الكوبون مطلوب.'],
            ]);
        }

        $record = PlatformRecord::query()
            ->where('resource', 'coupons-offers')
            ->where(function ($query) use ($normalizedCode) {
                $query->where('external_key', $normalizedCode)
                    ->orWhere('external_key', strtolower($normalizedCode));
            })
            ->first();

        if (! $record) {
            $record = PlatformRecord::query()
                ->where('resource', 'coupons-offers')
                ->get()
                ->first(function (PlatformRecord $candidate) use ($normalizedCode) {
                    $payload = (array) ($candidate->payload ?? []);

                    return strtoupper(trim((string) ($payload['code'] ?? '')))
                        === $normalizedCode;
                });
        }

        if (! $record) {
            throw ValidationException::withMessages([
                'coupon_code' => ['كود الخصم غير صحيح.'],
            ]);
        }

        $payload = (array) ($record->payload ?? []);

        /*
         * CREATOR_CONTRACT_GUARD_V2
         *
         * Normal ZAD coupons remain unaffected.
         * Creator-managed coupons fail closed unless the stored
         * assignment, coupon code, contract version and contract
         * state all match the current assignment.
         */
        $creatorManaged = ($payload['creatorContractManaged'] ?? false) === true;

        $creatorAssignment = LaunchCampaignCreator::query()
            ->with(['campaign', 'creator'])
            ->whereNotNull('coupon_code')
            ->whereRaw('UPPER(coupon_code) = ?', [$normalizedCode])
            ->first();

        if ($creatorManaged) {
            $managedAssignmentId = (int) ($payload['creatorAssignmentId'] ?? 0);
            $managedContractVersion = (int) ($payload['creatorContractVersion'] ?? 0);

            if (
                $managedAssignmentId <= 0
                || $managedContractVersion <= 0
                || $creatorAssignment === null
                || (int) $creatorAssignment->id !== $managedAssignmentId
                || (int) $creatorAssignment->contract_version !== $managedContractVersion
                || ! $creatorAssignment->contractIsActive()
            ) {
                throw ValidationException::withMessages([
                    'coupon_code' => [
                        'Creator coupon is not available because its contract link is invalid or inactive.',
                    ],
                ]);
            }
        } elseif (
            $creatorAssignment !== null
            && ! $creatorAssignment->contractIsActive()
        ) {
            throw ValidationException::withMessages([
                'coupon_code' => [
                    'Creator coupon is not available because its contract is inactive.',
                ],
            ]);
        }
        // COUPON_CHECKOUT_GUARDS_V3
        if (strtolower(trim((string) $record->status)) !== 'active') {
            throw ValidationException::withMessages([
                'coupon_code' => ['Coupon record is not active.'],
            ]);
        }

        $kind = strtolower(trim((string) ($payload['kind'] ?? '')));

        if ($kind !== 'coupon') {
            throw ValidationException::withMessages([
                'coupon_code' => ['This promotion is not a checkout coupon.'],
            ]);
        }

        $status = strtolower((string) ($payload['status'] ?? ''));

        if ($status !== 'active') {
            throw ValidationException::withMessages([
                'coupon_code' => ['هذا الكوبون غير نشط حاليًا.'],
            ]);
        }

        $today = Carbon::today();

        $startDate = trim((string) ($payload['startDate'] ?? ''));
        $endDate = trim((string) ($payload['endDate'] ?? ''));

        if ($startDate !== '' && $today->lt(Carbon::parse($startDate)->startOfDay())) {
            throw ValidationException::withMessages([
                'coupon_code' => ['هذا الكوبون لم يبدأ بعد.'],
            ]);
        }

        if ($endDate !== '' && $today->gt(Carbon::parse($endDate)->endOfDay())) {
            throw ValidationException::withMessages([
                'coupon_code' => ['انتهت صلاحية هذا الكوبون.'],
            ]);
        }

        $minimumOrder = max(0, (float) ($payload['minimumOrder'] ?? 0));

        if ($subtotal < $minimumOrder) {
            throw ValidationException::withMessages([
                'coupon_code' => [
                    'الحد الأدنى لاستخدام هذا الكوبون هو '
                    .number_format($minimumOrder, 2).' ر.س.',
                ],
            ]);
        }

        $usageLimit = max(0, (int) ($payload['usageLimit'] ?? 0));

        if ($usageLimit > 0) {
            $used = OrderCouponUsage::query()
                ->where('coupon_code', $normalizedCode)
                ->whereIn('status', ['applied', 'consumed'])
                ->when(
                    $currentOrderId !== null,
                    fn ($query) => $query->where('order_id', '<>', $currentOrderId)
                )
                ->count();

            if ($used >= $usageLimit) {
                throw ValidationException::withMessages([
                    'coupon_code' => ['تم الوصول إلى الحد الأعلى لاستخدام هذا الكوبون.'],
                ]);
            }
        }

        $perCustomerLimit = max(0, (int) ($payload['perCustomerLimit'] ?? 0));

        if ($customerId && $perCustomerLimit > 0) {
            $customerUses = OrderCouponUsage::query()
                ->where('customer_id', $customerId)
                ->where('coupon_code', $normalizedCode)
                ->whereIn('status', ['applied', 'consumed'])
                ->when(
                    $currentOrderId !== null,
                    fn ($query) => $query->where('order_id', '<>', $currentOrderId)
                )
                ->count();

            if ($customerUses >= $perCustomerLimit) {
                throw ValidationException::withMessages([
                    'coupon_code' => ['لقد وصلت إلى الحد المسموح لاستخدام هذا الكوبون.'],
                ]);
            }
        }

        if (
            ! empty($payload['firstOrderOnly'])
            && $customerId
            && \App\Models\Order::query()
                ->where('customer_id', $customerId)
                ->when(
                    $currentOrderId !== null,
                    fn ($query) => $query->where('id', '<>', $currentOrderId)
                )
                ->whereNotIn('status', [
                    \App\Models\Order::STATUS_CANCELLED,
                    \App\Models\Order::STATUS_REJECTED,
                ])
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'coupon_code' => ['هذا الكوبون متاح للطلب الأول فقط.'],
            ]);
        }

        // ZAD_COUPON_FUNDING_ENGINE_FINAL
        // Existing ordinary coupons keep subtotal behavior unless a scope exists.
        // Creator/influencer coupons default to delivery discount.
        $discountScope = strtolower(trim((string) (
            $payload['discountScope']
            ?? ($creatorAssignment !== null ? 'delivery' : 'subtotal')
        )));

        if (! in_array($discountScope, ['delivery', 'subtotal'], true)) {
            $discountScope = $creatorAssignment !== null ? 'delivery' : 'subtotal';
        }

        $discountBase = $discountScope === 'delivery'
            ? max(0, $deliveryFee)
            : max(0, $subtotal);

        $zadSharePercent = max(0, min(100, (float) ($payload['zadSharePercent'] ?? 100)));
        $familySharePercent = max(0, min(100, (float) ($payload['familySharePercent'] ?? 0)));
        $driverSharePercent = max(0, min(100, (float) ($payload['driverSharePercent'] ?? 0)));

        $fundingTotal = round(
            $zadSharePercent + $familySharePercent + $driverSharePercent,
            4
        );

        if (abs($fundingTotal - 100) > 0.001) {
            throw ValidationException::withMessages([
                'coupon_code' => [
                    'إعداد تمويل الكوبون غير صحيح: مجموع زاد والأسرة والمندوب يجب أن يساوي 100%.',
                ],
            ]);
        }

        $discountType = strtolower((string) ($payload['discountType'] ?? ''));
        $value = max(0, (float) ($payload['value'] ?? 0));
        $maxDiscount = max(0, (float) ($payload['maxDiscount'] ?? 0));

        $discount = $this->calculateDiscount(
            $discountType,
            $value,
            $discountBase,
            $maxDiscount
        );

        $familyFunded = round($discount * $familySharePercent / 100, 2);
        $driverFunded = round($discount * $driverSharePercent / 100, 2);
        $zadFunded = round(max(0, $discount - $familyFunded - $driverFunded), 2);

        return [
            'record' => $record,
            'code' => $normalizedCode,
            'discount_type' => $discountType,
            'discount_value' => round($value, 2),
            'max_discount' => round($maxDiscount, 2),
            'minimum_order' => round($minimumOrder, 2),
            'discount_amount' => $discount,
            'discount_scope' => $discountScope,
            'zad_share_percent' => round($zadSharePercent, 2),
            'family_share_percent' => round($familySharePercent, 2),
            'driver_share_percent' => round($driverSharePercent, 2),
            'zad_funded_amount' => $zadFunded,
            'family_funded_amount' => $familyFunded,
            'driver_funded_amount' => $driverFunded,
            'subtotal' => round($subtotal, 2),
            'delivery_fee' => round($deliveryFee, 2),
            'snapshot' => [
                'code' => $normalizedCode,
                'kind' => $payload['kind'] ?? 'coupon',
                'nameAr' => $payload['nameAr'] ?? null,
                'nameEn' => $payload['nameEn'] ?? null,
                'discountType' => $discountType,
                'discountScope' => $discountScope,
                'zadSharePercent' => round($zadSharePercent, 2),
                'familySharePercent' => round($familySharePercent, 2),
                'driverSharePercent' => round($driverSharePercent, 2),
                'zadFundedAmount' => $zadFunded,
                'familyFundedAmount' => $familyFunded,
                'driverFundedAmount' => $driverFunded,
                'value' => round($value, 2),
                'maxDiscount' => round($maxDiscount, 2),
                'minimumOrder' => round($minimumOrder, 2),
                'usageLimit' => $usageLimit,
                'perCustomerLimit' => $perCustomerLimit,
                'firstOrderOnly' => (bool) ($payload['firstOrderOnly'] ?? false),
                'startDate' => $startDate ?: null,
                'endDate' => $endDate ?: null,
            ],
        ];
    }
}
