<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PlatformOwnerSynchronizer
{
    public function sync(): ?User
    {
        $email = strtolower(trim(
            (string) config('zad.owner.email')
        ));

        $password = (string) config('zad.owner.password');

        /*
        |--------------------------------------------------------------------------
        | عدم تنفيذ المزامنة عند غياب متغيرات البيئة
        |--------------------------------------------------------------------------
        */

        if ($email === '' || $password === '') {
            return null;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                'قيمة ZAD_OWNER_EMAIL ليست بريدًا إلكترونيًا صحيحًا.'
            );
        }

        if (mb_strlen($password) < 10) {
            throw new RuntimeException(
                'يجب ألا تقل كلمة مرور مالك المنصة عن 10 أحرف.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | البحث عن مالك المنصة الحالي
        |--------------------------------------------------------------------------
        */

        $owner = User::query()
            ->withTrashed()
            ->where('is_platform_owner', true)
            ->first();

        /*
        |--------------------------------------------------------------------------
        | البحث بالبريد عند عدم وجود مالك مسجل
        |--------------------------------------------------------------------------
        */

        if (! $owner) {
            $owner = User::query()
                ->withTrashed()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();
        }

        /*
        |--------------------------------------------------------------------------
        | إنشاء حساب جديد عند عدم وجود حساب
        |--------------------------------------------------------------------------
        */

        if (! $owner) {
            $owner = new User();
        }

        $isNewOwner = ! $owner->exists;

        if ($owner->exists && $owner->trashed()) {
            $owner->restore();
        }

        $ownerData = [
            'name' => 'مؤيد منصور الهوساوي',
            'name_ar' => 'مؤيد منصور الهوساوي',
            'name_en' => 'Mouayad Mansour Al-Hosawi',

            'email' => $email,
            'email_verified_at' => $owner->email_verified_at ?? now(),

            'status' => 'active',
            'is_approved' => true,
            'approved_at' => $owner->approved_at ?? now(),

            'locale' => 'ar',
            'timezone' => 'Asia/Riyadh',

            'is_platform_owner' => true,
            'is_protected' => true,
            'role_locked' => true,
            'permissions_locked' => true,

            'suspended_at' => null,
            'suspension_reason' => null,
            'deleted_at' => null,
        ];

        /*
        |--------------------------------------------------------------------------
        | عدم إعادة تعيين كلمة المرور عند كل تسجيل دخول
        |--------------------------------------------------------------------------
        |
        | كلمة المرور من متغيرات البيئة تستخدم فقط عند إنشاء الحساب لأول مرة
        | أو عندما يكون السجل الحالي بلا كلمة مرور.
        |
        */

        if (
            $isNewOwner ||
            ! is_string($owner->password) ||
            trim($owner->password) === ''
        ) {
            $ownerData['password'] = Hash::make($password);
            $ownerData['password_changed_at'] = now();
        }

        $owner->forceFill($ownerData);
        $owner->save();

        Log::info('Platform owner account synchronized.', [
            'user_id' => $owner->id,
            'email' => $owner->email,
        ]);

        return $owner->fresh();
    }
}