<?php

namespace App\Services;

use App\Models\SponsoredCreatorCoupon;
use Illuminate\Validation\ValidationException;

final class SponsoredCouponValidationService
{
    public function validateCoupon(string $plainCode, int $storeId, array $items, int $grossCents): SponsoredCreatorCoupon
    {
        $code = trim($plainCode);
        if ($code === "" || strlen($code) > 100) {
            throw ValidationException::withMessages(["coupon" => "Invalid sponsored coupon."]);
        }

        $coupon = SponsoredCreatorCoupon::query()
            ->where("code_hash", hash("sha256", $code))
            ->first();

        if (!$coupon || !$coupon->isAvailable() || (int) $coupon->store_id !== $storeId) {
            throw ValidationException::withMessages(["coupon" => "Sponsored coupon unavailable."]);
        }

        if ($grossCents <= 0 || $grossCents > (int) round((float) $coupon->gross_cap * 100)) {
            throw ValidationException::withMessages(["coupon" => "Sponsored coupon cap exceeded."]);
        }

        $allowed = [];
        foreach ((array) $coupon->allowed_products as $row) {
            $allowed[(int) $row["product_id"]] = (int) $row["max_quantity"];
        }

        $requested = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item["product_id"], $item["quantity"])) {
                throw ValidationException::withMessages(["items" => "Invalid sponsored items."]);
            }
            $id = (int) $item["product_id"];
            $quantity = (int) $item["quantity"];
            if ($id <= 0 || $quantity <= 0 || !isset($allowed[$id])) {
                throw ValidationException::withMessages(["items" => "Product not allowed."]);
            }
            $requested[$id] = ($requested[$id] ?? 0) + $quantity;
            if ($requested[$id] > $allowed[$id]) {
                throw ValidationException::withMessages(["items" => "Sponsored quantity exceeded."]);
            }
        }

        if (count($requested) === 0) {
            throw ValidationException::withMessages(["items" => "No sponsored items."]);
        }

        return $coupon;
    }
}