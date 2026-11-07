<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SponsoredCreatorCoupon;
use App\Services\SponsoredCreatorCouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SponsoredCreatorCouponController extends Controller
{
    public function __construct(
        private readonly SponsoredCreatorCouponService $service
    ) {}

    public function store(Request $request): JsonResponse
    {
        $result = $this->service->createDraft(
            $request->user(),
            $request->all()
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء كوبون المؤثر كمسودة غير نشطة.',
            'coupon' => $this->serializeCoupon($result['coupon']),
            'one_time_plain_code' => $result['one_time_plain_code'],
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function activate(
        Request $request,
        int $coupon
    ): JsonResponse {
        $data = $request->validate([
            'duration_minutes' => [
                'required', 'integer', 'min:1', 'max:60',
            ],
        ]);

        $record = $this->service->activate(
            $request->user(),
            $coupon,
            (int) $data['duration_minutes']
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تفعيل كوبون المؤثر.',
            'coupon' => $this->serializeCoupon($record),
        ])->header('Cache-Control', 'no-store');
    }

    public function revoke(
        Request $request,
        int $coupon
    ): JsonResponse {
        $record = $this->service->revoke(
            $request->user(),
            $coupon
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء كوبون المؤثر.',
            'coupon' => $this->serializeCoupon($record),
        ])->header('Cache-Control', 'no-store');
    }

    private function serializeCoupon(
        SponsoredCreatorCoupon $coupon
    ): array {
        return [
            'id' => $coupon->id,
            'creator_id' => $coupon->creator_id,
            'store_id' => $coupon->store_id,
            'allowed_products' => $coupon->allowed_products,
            'gross_cap' => $coupon->gross_cap,
            'status' => $coupon->status,
            'activated_at' => $coupon->activated_at?->toIso8601String(),
            'expires_at' => $coupon->expires_at?->toIso8601String(),
            'revoked_at' => $coupon->revoked_at?->toIso8601String(),
            'consumed_at' => $coupon->consumed_at?->toIso8601String(),
        ];
    }
}