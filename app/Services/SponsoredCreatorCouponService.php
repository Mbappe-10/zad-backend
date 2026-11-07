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
            'creator_id' => ['required', 'integer', 'exists:launch_creators,id'],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'allowed_products' => ['required', 'array', 'min:1', 'max:30'],
            'allowed_products.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'allowed_products.*.max_quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'gross_cap' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'code_label' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9-]{4,64}\z/'],
        ])->validate();

        $productIds = array_column($data['allowed_products'], 'product_id');

        $matchingProducts = DB::table('products')
            ->whereIn('id', $productIds)
            ->where('store_id', $data['store_id'])
            ->count();

        if ($matchingProducts !== count($productIds)) {
            throw ValidationException::withMessages([
                'allowed_products' => 'All products must belong to the selected store.',
            ]);
        }

        $plainCode = 'ZSC-' . strtoupper(bin2hex(random_bytes(16)));

        $coupon = DB::transaction(function () use ($actor, $data, $plainCode) {
            $coupon = new SponsoredCreatorCoupon();
            $coupon->creator_id = $data['creator_id'];
            $coupon->store_id = $data['store_id'];
            $coupon->created_by = $actor->getKey();
            $coupon->allowed_products = $data['allowed_products'];
            $coupon->gross_cap = $data['gross_cap'];
            $coupon->display_name = isset($data['display_name'])
                ? trim($data['display_name'])
                : null;
            $coupon->code_label = isset($data['code_label'])
                ? strtoupper(trim($data['code_label']))
                : null;
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
        int $durationMinutes = 15
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