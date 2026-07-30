<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class PlatformOwnerSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim(
            (string) env('ZAD_OWNER_EMAIL', '')
        ));

        $password = (string) env(
            'ZAD_OWNER_PASSWORD',
            ''
        );

        if ($email === '') {
            throw new RuntimeException(
                'متغير البيئة ZAD_OWNER_EMAIL غير موجود.'
            );
        }

        if ($password === '') {
            throw new RuntimeException(
                'متغير البيئة ZAD_OWNER_PASSWORD غير موجود.'
            );
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                'قيمة ZAD_OWNER_EMAIL ليست بريدًا إلكترونيًا صحيحًا.'
            );
        }

        if (strlen($password) < 8) {
            throw new RuntimeException(
                'يجب ألا تقل كلمة مرور مالك المنصة عن 8 أحرف.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | البحث عن مالك المنصة الحالي
        |--------------------------------------------------------------------------
        |
        | نبحث أولًا عن الحساب المحمي الحالي حتى نستطيع تغيير بريده
        | دون إنشاء حساب مالك آخر.
        |
        */

        $owner = User::query()
            ->withTrashed()
            ->where('is_platform_owner', true)
            ->first();

        /*
        |--------------------------------------------------------------------------
        | البحث بالبريد عند عدم وجود مالك سابق
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
        | إنشاء الحساب أو تحديثه
        |--------------------------------------------------------------------------
        */

        if (! $owner) {
            $owner = new User();
        }

        if ($owner->trashed()) {
            $owner->restore();
        }

        $owner->forceFill([
            'name' => 'مؤيد منصور الهوساوي',
            'name_ar' => 'مؤيد منصور الهوساوي',
            'name_en' => 'Mouayad Mansour Al-Hosawi',

            'email' => $email,
            'email_verified_at' => now(),

            'password' => Hash::make($password),
            'password_changed_at' => now(),

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
        ]);

        $owner->save();

        $this->command?->info(
            "تم تجهيز حساب مالك المنصة: {$owner->email}"
        );
    }
}