<?php

namespace App\Services;

// ZAD_DELIVERY_OTP_V1
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderDeliveryVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DeliveryVerificationService
{
    public const MAX_ATTEMPTS = 5;

    public function issue(Order $order): OrderDeliveryVerification
    {
        return DB::transaction(function () use ($order): OrderDeliveryVerification {
            $record = OrderDeliveryVerification::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($record !== null && $record->verified_at !== null) {
                return $record;
            }

            if ($record !== null && $record->expires_at?->isFuture()) {
                return $record;
            }

            $plainCode = (string) random_int(1000, 9999);

            return OrderDeliveryVerification::query()->updateOrCreate(
                ['order_id' => $order->id],
                [
                    'code' => $plainCode,
                    'code_hash' => Hash::make($plainCode),
                    'failed_attempts' => 0,
                    'expires_at' => now()->addHours(2),
                    'verified_at' => null,
                    'verified_by_driver_id' => null,
                ],
            );
        });
    }

    public function verify(Order $order, Driver $driver, string $plainCode): OrderDeliveryVerification
    {
        $failure = null;

        $record = DB::transaction(function () use ($order, $driver, $plainCode, &$failure): OrderDeliveryVerification {
            $record = OrderDeliveryVerification::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                $failure = 'لم يتم إنشاء رمز تسليم لهذا الطلب.';

                return new OrderDeliveryVerification();
            }

            if ($record->verified_at !== null) {
                return $record;
            }

            if ($record->failed_attempts >= self::MAX_ATTEMPTS) {
                $failure = 'تم تجاوز عدد المحاولات المسموح بها. اطلب من العميل تحديث رمز التسليم.';

                return $record;
            }

            if ($record->expires_at?->isPast()) {
                $failure = 'انتهت صلاحية رمز التسليم. اطلب من العميل تحديث الصفحة للحصول على رمز جديد.';

                return $record;
            }

            if (! Hash::check($plainCode, $record->code_hash)) {
                $record->increment('failed_attempts');
                $record->refresh();
                $remaining = max(self::MAX_ATTEMPTS - $record->failed_attempts, 0);
                $failure = "رمز التسليم غير صحيح. المحاولات المتبقية: {$remaining}.";

                return $record;
            }

            $record->update([
                'verified_at' => now(),
                'verified_by_driver_id' => $driver->id,
            ]);

            return $record->fresh();
        });

        if ($failure !== null) {
            throw ValidationException::withMessages(['code' => [$failure]]);
        }

        return $record;
    }

    /** @return array<string, mixed> */
    public function customerPayload(Order $order): array
    {
        $record = $order->deliveryVerification()->first();

        if (
            $order->status === Order::STATUS_DELIVERING &&
            ($record === null || ($record->verified_at === null && $record->expires_at?->isPast()))
        ) {
            $record = $this->issue($order);
        }

        if ($record === null) {
            return [
                'available' => false,
                'status' => 'not_available',
                'code' => null,
                'verified_at' => null,
                'expires_at' => null,
                'attempts_remaining' => 0,
            ];
        }

        return [
            'available' => true,
            'status' => $record->verified_at !== null ? 'verified' : 'waiting',
            'code' => $record->verified_at === null ? $record->code : null,
            'verified_at' => $record->verified_at,
            'expires_at' => $record->expires_at,
            'attempts_remaining' => max(self::MAX_ATTEMPTS - $record->failed_attempts, 0),
        ];
    }
}