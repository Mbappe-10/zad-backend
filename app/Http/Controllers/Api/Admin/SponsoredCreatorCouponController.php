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

    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->is_platform_owner === true,
            403,
            'Owner access required.'
        );

        $coupons = SponsoredCreatorCoupon::query()
            ->with('creator:id,name')
            ->whereNull('archived_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $coupons,
        ])->header('Cache-Control', 'no-store');
    }
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

    public function updateCap(Request $request, int $coupon): JsonResponse
    {
        $data = $request->validate(['gross_cap' => ['required', 'numeric', 'gt:0', 'max:100000']]);
        $record = $this->service->updateCap($request->user(), $coupon, (float) $data['gross_cap']);
        return response()->json(['success' => true, 'coupon' => $this->serializeCoupon($record)])
            ->header('Cache-Control', 'no-store');
    }

    public function pause(Request $request, int $coupon): JsonResponse
    {
        $record = $this->service->pause($request->user(), $coupon);

        return response()->json([
            'success' => true,
            'coupon' => $this->serializeCoupon($record),
        ])->header('Cache-Control', 'no-store');
    }

    public function resume(Request $request, int $coupon): JsonResponse
    {
        $record = $this->service->resume($request->user(), $coupon);

        return response()->json([
            'success' => true,
            'coupon' => $this->serializeCoupon($record),
        ])->header('Cache-Control', 'no-store');
    }

    public function extend(Request $request, int $coupon): JsonResponse
    {
        $data = $request->validate([
            'minutes' => ['required', 'integer', 'in:5,10,20'],
        ]);

        $record = $this->service->extend(
            $request->user(),
            $coupon,
            (int) $data['minutes']
        );

        return response()->json([
            'success' => true,
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

    public function archive(Request $request, int $coupon): JsonResponse
    {
        $record = $this->service->archive(
            $request->user(),
            $coupon
        );

        return response()->json([
            'success' => true,
            'message' => 'Coupon archived successfully.',
            'coupon' => $this->serializeCoupon($record),
        ])->header('Cache-Control', 'no-store');
    }

    private function serializeCoupon(
        SponsoredCreatorCoupon $coupon
    ): array {
        return [
            'id' => $coupon->id,
            'display_name' => $coupon->display_name,
            'code_label' => $coupon->code_label,
            'creator_id' => $coupon->creator_id,
            'store_id' => $coupon->store_id,
            'allowed_products' => $coupon->allowed_products,
            'gross_cap' => $coupon->gross_cap,
            'status' => $coupon->status,
            'activated_at' => $coupon->activated_at?->toIso8601String(),
            'expires_at' => $coupon->expires_at?->toIso8601String(),
            'revoked_at' => $coupon->revoked_at?->toIso8601String(),
            'consumed_at' => $coupon->consumed_at?->toIso8601String(),
            'archived_at' => $coupon->archived_at?->toIso8601String(),
        ];
    }
}