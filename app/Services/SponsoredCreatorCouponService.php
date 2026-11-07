<?php

namespace App\Services;

use App\Models\SponsoredCreatorCoupon;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SponsoredCreatorCouponService
{
    private function requireOwner(User $actor): void
    {
        abort_unless(
            $actor->exists && $actor->is_platform_owner === true,
            403,
            'Owner access required.'
        );
    }

    public function createDraft(User $actor, array $input): array
    {
        $this->requireOwner($actor);

        $data = Validator::make($input, [
            'creator_id' => ['nullable', 'integer', 'exists:launch_creators,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'allowed_products' => ['nullable', 'array', 'max:30'],
            'allowed_products.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'allowed_products.*.max_quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'gross_cap' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'display_name' => ['required', 'string', 'max:120'],
            'code_label' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9-]{4,64}\z/'],
            'coupon_code' => ['required', 'string', 'regex:/\A[A-Za-z0-9-]{6,64}\z/'],
        ])->validate();

        $productIds = array_column($data['allowed_products'] ?? [], 'product_id');

        $matchingProducts = DB::table('products')
            ->whereIn('id', $productIds)
            ->when(isset($data['store_id']), fn ($q) => $q->where('store_id', $data['store_id']))
            ->count();

        if ($productIds !== [] && ($data['store_id'] ?? null) === null) {
            throw ValidationException::withMessages(['store_id' => 'Store required when restricting products.']);
        }

        if ($matchingProducts !== count($productIds)) {
            throw ValidationException::withMessages([
                'allowed_products' => 'All products must belong to the selected store.',
            ]);
        }

        $plainCode = strtoupper(trim($data['coupon_code']));
        if (SponsoredCreatorCoupon::query()->where('code_hash', hash('sha256', $plainCode))->exists()) {
            throw ValidationException::withMessages(['coupon_code' => 'Coupon code already exists.']);
        }

        $coupon = DB::transaction(function () use ($actor, $data, $plainCode) {
            $coupon = new SponsoredCreatorCoupon();
            $coupon->creator_id = $data['creator_id'] ?? null;
            $coupon->store_id = $data['store_id'] ?? null;
            $coupon->created_by = $actor->getKey();
            $coupon->allowed_products = $data['allowed_products'] ?? [];
            $coupon->gross_cap = $data['gross_cap'];
            $coupon->display_name = isset($data['display_name'])
                ? trim($data['display_name'])
                : null;
            $coupon->code_label = $plainCode;
            $coupon->code_hash = hash('sha256', $plainCode);
            $coupon->status = SponsoredCreatorCoupon::STATUS_DRAFT;
            $coupon->save();

            return $coupon;
        });

        return [
            'coupon' => $coupon,
            'one_time_plain_code' => $plainCode,
        ];
    }

    public function activate(
        User $actor,
        int $couponId,
        int $durationMinutes = 5
    ): SponsoredCreatorCoupon {
        $this->requireOwner($actor);

        if ($durationMinutes < 1 || $durationMinutes > 60) {
            throw ValidationException::withMessages([
                'duration_minutes' => 'Duration must be between 1 and 60 minutes.',
            ]);
        }

        return DB::transaction(function () use ($couponId, $durationMinutes) {
            $coupon = SponsoredCreatorCoupon::query()
                ->lockForUpdate()
                ->findOrFail($couponId);

            if (
                $coupon->status !== SponsoredCreatorCoupon::STATUS_DRAFT
                || $coupon->activated_at !== null
                || $coupon->archived_at !== null
                || $coupon->revoked_at !== null
                || $coupon->consumed_at !== null
                || $coupon->consumed_order_id !== null
            ) {
                throw new HttpException(409, 'Coupon cannot be activated.');
            }

            $coupon->status = SponsoredCreatorCoupon::STATUS_ACTIVE;
            $coupon->activated_at = now();
            $coupon->expires_at = now()->addMinutes($durationMinutes);
            $coupon->save();

            return $coupon;
        });
    }

    public function updateCap(User $actor, int $couponId, float $grossCap): SponsoredCreatorCoupon
    {
        $this->requireOwner($actor);
        if (!is_finite($grossCap) || $grossCap <= 0 || $grossCap > 100000) {
            throw ValidationException::withMessages(['gross_cap' => 'Invalid financial cap.']);
        }
        return DB::transaction(function () use ($couponId, $grossCap) {
            $coupon = SponsoredCreatorCoupon::query()->lockForUpdate()->findOrFail($couponId);
            if ($coupon->consumed_at !== null || $coupon->consumed_order_id !== null
                || in_array($coupon->status, [SponsoredCreatorCoupon::STATUS_CONSUMED, SponsoredCreatorCoupon::STATUS_REVOKED], true)
                || $coupon->archived_at !== null) {
                throw new HttpException(409, 'Used, revoked or archived coupon cannot be edited.');
            }
            $coupon->gross_cap = round($grossCap, 2);
            $coupon->save();
            return $coupon;
        });
    }

    public function pause(User $actor, int $couponId): SponsoredCreatorCoupon
    {
        $this->requireOwner($actor);

        return DB::transaction(function () use ($couponId) {
            $coupon = SponsoredCreatorCoupon::query()
                ->lockForUpdate()
                ->findOrFail($couponId);

            if ($coupon->status !== SponsoredCreatorCoupon::STATUS_ACTIVE
                || !$coupon->isAvailable()) {
                throw new HttpException(409, 'Only available active coupons can be paused.');
            }

            $coupon->status = 'paused';
            $coupon->save();

            return $coupon;
        });
    }

    public function resume(User $actor, int $couponId): SponsoredCreatorCoupon
    {
        $this->requireOwner($actor);

        return DB::transaction(function () use ($couponId) {
            $coupon = SponsoredCreatorCoupon::query()
                ->lockForUpdate()
                ->findOrFail($couponId);

            if ($coupon->status !== 'paused'
                || $coupon->expires_at === null
                || !$coupon->expires_at->isFuture()
                || $coupon->archived_at !== null
                || $coupon->revoked_at !== null
                || $coupon->consumed_at !== null
                || $coupon->consumed_order_id !== null) {
                throw new HttpException(409, 'Coupon cannot be resumed.');
            }

            $coupon->status = SponsoredCreatorCoupon::STATUS_ACTIVE;
            $coupon->save();

            return $coupon;
        });
    }

    public function extend(User $actor, int $couponId, int $minutes): SponsoredCreatorCoupon
    {
        $this->requireOwner($actor);

        if (!in_array($minutes, [5, 10, 20], true)) {
            throw ValidationException::withMessages([
                'minutes' => 'Extension must be 5, 10 or 20 minutes.',
            ]);
        }

        return DB::transaction(function () use ($couponId, $minutes) {
            $coupon = SponsoredCreatorCoupon::query()
                ->lockForUpdate()
                ->findOrFail($couponId);

            if (!in_array($coupon->status, [
                    SponsoredCreatorCoupon::STATUS_ACTIVE,
                    'paused',
                ], true)
                || $coupon->expires_at === null
                || !$coupon->expires_at->isFuture()
                || $coupon->archived_at !== null
                || $coupon->revoked_at !== null
                || $coupon->consumed_at !== null
                || $coupon->consumed_order_id !== null) {
                throw new HttpException(409, 'Coupon cannot be extended.');
            }

            $coupon->expires_at = $coupon->expires_at->copy()->addMinutes($minutes);
            $coupon->save();

            return $coupon;
        });
    }
    public function archive(User $actor, int $couponId): SponsoredCreatorCoupon
    {
        $this->requireOwner($actor);

        return DB::transaction(function () use ($couponId) {
            $coupon = SponsoredCreatorCoupon::query()
                ->lockForUpdate()
                ->findOrFail($couponId);

            if ($coupon->archived_at !== null) {
                return $coupon;
            }

            if (
                $coupon->status === SponsoredCreatorCoupon::STATUS_ACTIVE
                || $coupon->status === 'paused'
            ) {
                throw new HttpException(
                    409,
                    'Active or paused coupons must be revoked before archiving.'
                );
            }

            $coupon->archived_at = now();
            $coupon->save();

            return $coupon;
        });
    }
    public function revoke(
        User $actor,
        int $couponId
    ): SponsoredCreatorCoupon {
        $this->requireOwner($actor);

        return DB::transaction(function () use ($couponId) {
            $coupon = SponsoredCreatorCoupon::query()
                ->lockForUpdate()
                ->findOrFail($couponId);

            if (
                $coupon->status === SponsoredCreatorCoupon::STATUS_CONSUMED
                || $coupon->consumed_at !== null
                || $coupon->consumed_order_id !== null
            ) {
                throw new HttpException(409, 'Consumed coupon cannot be revoked.');
            }

            if ($coupon->status === SponsoredCreatorCoupon::STATUS_REVOKED) {
                return $coupon;
            }

            $coupon->status = SponsoredCreatorCoupon::STATUS_REVOKED;
            $coupon->revoked_at = now();
            $coupon->save();

            return $coupon;
        });
    }
}