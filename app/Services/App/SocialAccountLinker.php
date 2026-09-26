<?php

namespace App\Services\App;

use App\Models\AppProfile;
use App\Models\City;
use App\Models\Driver;
use App\Models\ProductiveFamily;
use App\Models\PlatformControl;
use App\Models\Store;
use App\Models\User;
use App\Support\InternalTesting;
use Illuminate\Validation\ValidationException;

class SocialAccountLinker
{
    /**
     * Resolve one canonical ZADSYNC user for a verified Google identity.
     *
     * @return array{0: User, 1: bool}
     */
    public function resolveGoogleUser(
        string $providerUserId,
        string $email,
        string $name,
        string $joinType,
    ): array {
        $providerUser = User::query()
            ->where('auth_provider', 'google')
            ->where('provider_user_id', $providerUserId)
            ->lockForUpdate()
            ->first();

        $emailUser = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->lockForUpdate()
            ->first();

        $accountUser = $this->linkedAccountUser(
            $email,
            $joinType,
        );

        // The user already attached to the operational family/driver record
        // is authoritative. A previous broken social-login attempt may have
        // created a second, empty user carrying the same Google identity.
        $canonicalUser = $accountUser
            ?? $emailUser
            ?? $providerUser;

        $isNewUser = $canonicalUser === null;

        if ($isNewUser) {
            abort_unless(
                (bool) data_get(
                    PlatformControl::query()
                        ->where('section', 'platform')
                        ->value('value'),
                    'registrationsEnabled',
                    true,
                ),
                403,
                'التسجيل متوقف مؤقتًا بقرار من إدارة المنصة.',
            );
        }

        if ($canonicalUser !== null) {
            $duplicates = collect([
                $providerUser,
                $emailUser,
            ])
                ->filter()
                ->unique(
                    static fn (User $candidate): int =>
                        (int) $candidate->id,
                );

            foreach ($duplicates as $duplicate) {
                if ($duplicate->isNot($canonicalUser)) {
                    $this->retireOrphanSocialUser($duplicate);
                }
            }
        }

        if ($canonicalUser === null) {
            $canonicalUser = new User();
            $canonicalUser->forceFill([
                'name' => $name,
                'name_ar' => $name,
                'email' => $email,
                'email_verified_at' => now(),
                'auth_provider' => 'google',
                'provider_user_id' => $providerUserId,
                'status' => 'active',
                'is_approved' => true,
                'locale' => 'ar',
                'timezone' => 'Asia/Riyadh',
                'last_login_at' => now(),
            ])->save();

            return [$canonicalUser, true];
        }

        $storedEmail = strtolower(trim((string) $canonicalUser->email));

        if ($storedEmail !== '' && $storedEmail !== $email) {
            throw $this->linkingConflict(
                'بريد Google لا يطابق البريد المسجل للحساب.',
            );
        }

        $authProvider = trim((string) $canonicalUser->auth_provider);
        $storedProviderId = trim(
            (string) $canonicalUser->provider_user_id,
        );

        if (
            $authProvider !== ''
            && (
                $authProvider !== 'google'
                || (
                    $storedProviderId !== ''
                    && $storedProviderId !== $providerUserId
                )
            )
        ) {
            throw $this->linkingConflict(
                'الحساب مرتبط مسبقًا بهوية دخول أخرى.',
            );
        }

        if ($canonicalUser->status !== 'active') {
            abort(403, 'الحساب غير نشط.');
        }

        $canonicalUser->forceFill([
            'name' => filled($canonicalUser->name)
                ? $canonicalUser->name
                : $name,
            'name_ar' => filled($canonicalUser->name_ar)
                ? $canonicalUser->name_ar
                : $name,
            'email' => $email,
            'email_verified_at' =>
                $canonicalUser->email_verified_at ?? now(),
            'auth_provider' => 'google',
            'provider_user_id' => $providerUserId,
            'last_login_at' => now(),
        ])->save();

        return [$canonicalUser->fresh(), $isNewUser];
    }

    public function linkRoleAccount(
        User $user,
        AppProfile $profile,
        string $email,
        string $joinType,
        string $googleName,
    ): bool {
        $this->rejectWrongConfiguredRole($email, $joinType);

        return match ($joinType) {
            'productive_family' => $this->linkFamily(
                $user,
                $profile,
                $email,
            ),
            'driver' => $this->linkDriver(
                $user,
                $profile,
                $email,
                $googleName,
            ),
            default => false,
        };
    }

    private function linkFamily(
        User $user,
        AppProfile $profile,
        string $email,
    ): bool {
        $family = $profile->productive_family_id !== null
            ? ProductiveFamily::query()->find(
                $profile->productive_family_id,
            )
            : null;

        $family ??= ProductiveFamily::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        $family ??= $this->configuredFamily($email);

        if ($family === null) {
            $this->rejectUnlinkedInternalAccount('الأسرة المنتجة');

            return false;
        }

        if (! in_array($family->status, ['active', 'approved'], true)) {
            throw ValidationException::withMessages([
                'account' => [
                    'حساب الأسرة موجود لكنه غير معتمد أو غير نشط.',
                ],
            ]);
        }

        $store = $family->store()->first();

        if ($store === null) {
            throw ValidationException::withMessages([
                'account' => [
                    'حساب الأسرة موجود لكن المتجر المرتبط به غير موجود.',
                ],
            ]);
        }

        if (
            InternalTesting::enabled()
            && $this->configuredEmail('family') === $email
            && (
                $store->pickup_latitude === null
                || $store->pickup_longitude === null
            )
        ) {
            throw $this->configurationError(
                'أضف إحداثيات استلام المتجر التجريبية في إعدادات Render ثم أعد النشر.',
            );
        }

        $this->assertProfileTargetIsAvailable(
            'productive_family_id',
            $family->id,
            $user->id,
        );

        $profile->forceFill([
            'productive_family_id' => $family->id,
            'active_mode' => 'productive_family',
        ])->save();

        return true;
    }

    private function linkDriver(
        User $user,
        AppProfile $profile,
        string $email,
        string $googleName,
    ): bool {
        $driver = $profile->driver_id !== null
            ? Driver::query()->find($profile->driver_id)
            : null;

        $driver ??= Driver::query()
            ->where('user_id', $user->id)
            ->first();

        $configuredEmailMatches = InternalTesting::enabled()
            && $this->configuredEmail('driver') === $email;

        if ($driver === null) {
            $driver = $this->configuredDriver(
                $email,
                $user,
                $googleName,
                createIfMissing: true,
            );
        }

        if ($driver === null) {
            $this->rejectUnlinkedInternalAccount('المندوب');

            return false;
        }

        if (
            $driver->user_id !== null
            && (int) $driver->user_id !== (int) $user->id
        ) {
            throw $this->linkingConflict(
                'سجل المندوب مرتبط بمستخدم آخر.',
            );
        }

        $this->assertProfileTargetIsAvailable(
            'driver_id',
            $driver->id,
            $user->id,
        );

        $values = ['user_id' => $user->id];

        if ($configuredEmailMatches) {
            $values += [
                'status' => 'active',
                'application_status' => Driver::APPLICATION_APPROVED,
                'reviewed_at' => $driver->reviewed_at ?? now(),
            ];
        }

        $driver->forceFill($values)->save();

        $profile->forceFill([
            'driver_id' => $driver->id,
            'active_mode' => 'driver',
        ])->save();

        return true;
    }

    private function linkedAccountUser(
        string $email,
        string $joinType,
    ): ?User {
        if ($joinType === 'productive_family') {
            $family = ProductiveFamily::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first()
                ?? $this->configuredFamily($email);

            if ($family === null) {
                return null;
            }

            $profile = AppProfile::query()
                ->where('productive_family_id', $family->id)
                ->first();

            return $profile === null
                ? null
                : User::query()->find($profile->user_id);
        }

        if ($joinType === 'driver') {
            $driver = $this->configuredDriver(
                $email,
                null,
                '',
                createIfMissing: false,
            );

            return $driver?->user_id === null
                ? null
                : User::query()->find($driver->user_id);
        }

        return null;
    }

    private function configuredFamily(
        string $email,
    ): ?ProductiveFamily {
        if (! InternalTesting::enabled()) {
            return null;
        }

        $configuredEmail = $this->configuredEmail('family');

        if ($configuredEmail === '' || $configuredEmail !== $email) {
            return null;
        }

        $storeSlug = trim(
            (string) config(
                'internal_testing.family.store_slug',
                '',
            ),
        );

        if ($storeSlug === '') {
            throw $this->configurationError(
                'لم يُحدد رمز متجر الأسرة في إعدادات Render.',
            );
        }

        $store = Store::query()
            ->where('slug', $storeSlug)
            ->first();

        if ($store === null) {
            throw $this->configurationError(
                'متجر الأسرة المحدد في إعدادات Render غير موجود.',
            );
        }

        return ProductiveFamily::query()->find(
            $store->productive_family_id,
        );
    }

    private function configuredDriver(
        string $email,
        ?User $user,
        string $googleName,
        bool $createIfMissing,
    ): ?Driver {
        if (! InternalTesting::enabled()) {
            return null;
        }

        $configuredEmail = $this->configuredEmail('driver');

        if ($configuredEmail === '' || $configuredEmail !== $email) {
            return null;
        }

        $code = trim(
            (string) config('internal_testing.driver.code', ''),
        );

        if ($code === '') {
            throw $this->configurationError(
                'لم يُحدد كود المندوب في إعدادات Render.',
            );
        }

        $driver = Driver::query()
            ->withTrashed()
            ->where('code', $code)
            ->first();

        if ($driver !== null) {
            if ($driver->trashed()) {
                $driver->restore();
            }

            return $driver;
        }

        if (! $createIfMissing) {
            return null;
        }

        if (! (bool) config(
            'internal_testing.driver.create_if_missing',
            false,
        )) {
            throw $this->configurationError(
                'سجل المندوب غير موجود، وإنشاؤه التجريبي غير مفعّل.',
            );
        }

        if ($user === null) {
            throw $this->configurationError(
                'تعذر تحديد مستخدم المندوب لإنشاء السجل التجريبي.',
            );
        }

        $phone = InternalTesting::normalizePhone(
            (string) config('internal_testing.driver.phone', ''),
        );

        if ($phone === '') {
            throw $this->configurationError(
                'لم يُحدد رقم المندوب في إعدادات Render.',
            );
        }

        if (Driver::withTrashed()->where('phone', $phone)->exists()) {
            throw $this->linkingConflict(
                'رقم المندوب مرتبط بسجل آخر؛ راجع الكود والرقم في Render.',
            );
        }

        $name = trim(
            (string) config('internal_testing.driver.name', ''),
        );

        if ($name === '') {
            $name = $googleName !== ''
                ? $googleName
                : 'مندوب زاد سينك التجريبي';
        }

        $vehicleType = trim(
            (string) config(
                'internal_testing.driver.vehicle_type',
                'car',
            ),
        );

        if (! in_array(
            $vehicleType,
            [
                Driver::VEHICLE_SCOOTER,
                Driver::VEHICLE_MOTORCYCLE,
                Driver::VEHICLE_CAR,
            ],
            true,
        )) {
            $vehicleType = Driver::VEHICLE_CAR;
        }

        $cityId = City::query()
            ->where(
                'code',
                trim((string) config(
                    'internal_testing.driver.city_code',
                    'makkah',
                )),
            )
            ->value('id');

        return Driver::query()->create([
            'user_id' => $user->id,
            'city_id' => $cityId,
            'code' => $code,
            'name' => $name,
            'phone' => $phone,
            'vehicle_type' => $vehicleType,
            'status' => 'active',
            'application_status' => Driver::APPLICATION_APPROVED,
            'is_online' => false,
            'active_orders_count' => 0,
            'rating' => 0,
            'metadata' => [
                'source' => 'internal-testing-environment',
            ],
            'submitted_at' => now(),
            'reviewed_at' => now(),
        ]);
    }

    private function rejectWrongConfiguredRole(
        string $email,
        string $joinType,
    ): void {
        if (! InternalTesting::enabled()) {
            return;
        }

        $familyEmail = $this->configuredEmail('family');
        $driverEmail = $this->configuredEmail('driver');

        if ($joinType === 'driver' && $email === $familyEmail) {
            throw ValidationException::withMessages([
                'account' => [
                    'هذا البريد مخصص لحساب الأسرة. افتح دخول الأسرة المنتجة.',
                ],
            ]);
        }

        if ($joinType === 'productive_family' && $email === $driverEmail) {
            throw ValidationException::withMessages([
                'account' => [
                    'هذا البريد مخصص لحساب المندوب. افتح دخول المندوب.',
                ],
            ]);
        }
    }

    private function rejectUnlinkedInternalAccount(
        string $roleName,
    ): void {
        if (! InternalTesting::restrictRoleLogin()) {
            return;
        }

        throw ValidationException::withMessages([
            'account' => [
                "هذا البريد غير مربوط بحساب {$roleName} في نسخة الاختبار. راجع متغيرات Render.",
            ],
        ]);
    }

    private function assertProfileTargetIsAvailable(
        string $column,
        int $targetId,
        int $userId,
    ): void {
        $used = AppProfile::query()
            ->where($column, $targetId)
            ->where('user_id', '!=', $userId)
            ->exists();

        if ($used) {
            throw $this->linkingConflict(
                'الحساب التشغيلي مرتبط مسبقًا بمستخدم آخر.',
            );
        }
    }

    private function retireOrphanSocialUser(User $user): void
    {
        $profile = AppProfile::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        $hasLinkedAccount = $profile !== null
            && (
                $profile->customer_id !== null
                || $profile->productive_family_id !== null
                || $profile->driver_id !== null
            );

        if (
            $hasLinkedAccount
            || $user->auth_provider !== 'google'
            || blank($user->provider_user_id)
            || filled($user->password)
            || filled($user->phone)
            || $user->is_platform_owner
            || $user->is_protected
            || $user->roles()->exists()
            || Driver::query()->where('user_id', $user->id)->exists()
        ) {
            throw $this->linkingConflict(
                'عُثر على حسابين مرتبطين بالهوية نفسها ولا يمكن دمجهما تلقائيًا.',
            );
        }

        $user->tokens()->delete();
        $profile?->delete();

        $user->forceFill([
            // Release unique identity values before assigning them to the
            // canonical operational account. Soft-deleted rows still
            // participate in database unique constraints.
            'email' => null,
            'email_verified_at' => null,
            'auth_provider' => null,
            'provider_user_id' => null,
            'status' => 'inactive',
        ])->save();

        $user->delete();
    }

    private function configuredEmail(string $role): string
    {
        return strtolower(trim(
            (string) config(
                "internal_testing.{$role}.email",
                '',
            ),
        ));
    }

    private function configurationError(
        string $message,
    ): ValidationException {
        return ValidationException::withMessages([
            'configuration' => [$message],
        ]);
    }

    private function linkingConflict(
        string $message,
    ): ValidationException {
        return ValidationException::withMessages([
            'account' => [$message],
        ]);
    }
}
