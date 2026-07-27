<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class PlatformOwnerSeeder extends Seeder
{
    public function run(): void
    {
        $email = Str::lower(
            trim((string) env('ZAD_OWNER_EMAIL', 'owner@zad.local'))
        );

        $password = (string) env('ZAD_OWNER_PASSWORD');

        if ($password === '') {
            throw new RuntimeException(
                'يجب إضافة ZAD_OWNER_PASSWORD داخل ملف .env قبل تشغيل PlatformOwnerSeeder.'
            );
        }

        DB::transaction(function () use ($email, $password): void {
            $user = User::withTrashed()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if (!$user) {
                $user = new User();
            }

            $user->forceFill([
                'name' => 'مالك منصة زاد',
                'name_ar' => 'مالك منصة زاد',
                'name_en' => 'ZAD Platform Owner',
                'email' => $email,
                'phone' => null,
                'password' => Hash::make($password),
                'status' => 'active',
                'is_approved' => true,
                'approved_at' => now(),
                'suspended_at' => null,
                'suspension_reason' => null,
                'locale' => 'ar',
                'timezone' => 'Asia/Riyadh',
                'mfa_enabled' => false,
                'password_changed_at' => now(),
                'email_verified_at' => now(),
                'remember_token' => Str::random(60),
                'deleted_at' => null,
            ])->save();

            $role = Role::query()
                ->where('key', 'platform_owner')
                ->orWhere('slug', 'platform_owner')
                ->orWhere('code', 'platform_owner')
                ->first();

            if (!$role) {
                throw new RuntimeException(
                    'دور platform_owner غير موجود. شغّل Seeder الخاص بالأدوار أولًا.'
                );
            }

            $user->roles()->syncWithoutDetaching([
                $role->id => [
                    'assigned_by' => $user->id,
                    'expires_at' => null,
                ],
            ]);

            /*
             * حذف جميع توكنات المستخدم القديمة عند إعادة ضبط الحساب.
             */
            $user->tokens()->delete();

            $this->command?->info(
                "تم تجهيز مالك المنصة بنجاح: {$user->email}"
            );
        });
    }
}