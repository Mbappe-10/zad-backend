<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Driver;
use App\Models\ProductiveFamily;
use App\Models\User;
use Google\Client as GoogleClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SocialAuthController extends Controller
{
    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string'],
            'join_type' => ['required', 'in:productive_family,driver'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        /*
        |--------------------------------------------------------------------------
        | التحقق من Google ID Token
        |--------------------------------------------------------------------------
        */

        $client = new GoogleClient([
            'client_id' => config('services.google.client_id'),
        ]);

        $payload = $client->verifyIdToken($data['id_token']);

        if (! $payload || empty($payload['sub'])) {
            throw ValidationException::withMessages([
                'id_token' => ['تعذر التحقق من حساب Google. حاول مرة أخرى.'],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | هوية Google
        |--------------------------------------------------------------------------
        |
        | لا نحفظ البريد الإلكتروني.
        | لا نحفظ كلمة مرور.
        | نعتمد فقط على معرف Google الموثق.
        |
        */

        $providerUserId = (string) $payload['sub'];

        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            $name = 'مستخدم زاد';
        }

        /*
        |--------------------------------------------------------------------------
        | البحث عن الحساب أو إنشاؤه
        |--------------------------------------------------------------------------
        */

        [$user, $profile, $isNewUser] = DB::transaction(
            function () use (
                $providerUserId,
                $name,
                $data
            ): array {
                $user = User::query()
                    ->where('auth_provider', 'google')
                    ->where('provider_user_id', $providerUserId)
                    ->first();

                $isNewUser = $user === null;

                if ($isNewUser) {
                    $user = User::create([
                        'name' => $name,
                        'name_ar' => $name,

                        // لا Email
                        // لا Password
                        // لا Phone في هذه المرحلة

                        'auth_provider' => 'google',
                        'provider_user_id' => $providerUserId,

                        'status' => 'active',
                        'is_approved' => true,
                        'locale' => 'ar',
                        'timezone' => 'Asia/Riyadh',
                    ]);
                }

                if ($user->status !== 'active') {
                    abort(403, 'الحساب غير نشط.');
                }

                /*
                |--------------------------------------------------------------------------
                | App Profile
                |--------------------------------------------------------------------------
                */

                $profile = AppProfile::firstOrCreate(
                    [
                        'user_id' => $user->id,
                    ],
                    [
                        'roles' => [],
                        'active_mode' => $data['join_type'],
                    ],
                );

                /*
                |--------------------------------------------------------------------------
                | إضافة نوع الانضمام
                |--------------------------------------------------------------------------
                */

                $roles = $profile->roles ?? [];

                if (! in_array($data['join_type'], $roles, true)) {
                    $roles[] = $data['join_type'];
                }

                $profile->update([
                    'roles' => array_values(array_unique($roles)),
                    'active_mode' => $data['join_type'],
                ]);

                $user->forceFill([
                    'last_login_at' => now(),
                ])->save();

                return [$user, $profile->fresh(), $isNewUser];
            },
        );

        /*
        |--------------------------------------------------------------------------
        | إصدار Sanctum Token لتطبيق ZAD
        |--------------------------------------------------------------------------
        */

        $token = $user
            ->createToken(
                $data['device_name'] ?? 'zad-mobile-app',
            )
            ->plainTextToken;

        return response()->json([
            'message' => $isNewUser
                ? 'تم إنشاء حساب زاد بنجاح.'
                : 'تم تسجيل الدخول بنجاح.',

            'is_new_user' => $isNewUser,

            'token' => $token,

            'user' => [
                'id' => $user->id,
                'name' => $user->displayName('ar'),

                'auth_provider' => $user->auth_provider,

                'roles' => $profile->roles ?? [],
                'active_mode' => $profile->active_mode,

                'productive_family_id' =>
                    $profile->productive_family_id,

                'driver_id' =>
                    $profile->driver_id,
            ],

            'next_step' => $this->nextStep(
                $profile->active_mode,
                $profile,
            ),
        ]);
    }

    private function nextStep(
        ?string $activeMode,
        AppProfile $profile,
    ): string {
        if (
            $activeMode === 'productive_family'
            && (
                ! $profile->productive_family_id
                || ! ProductiveFamily::query()
                    ->whereKey($profile->productive_family_id)
                    ->whereHas('store')
                    ->exists()
            )
        ) {
            return 'complete_productive_family_profile';
        }

        if (
            $activeMode === 'driver'
            && (
                ! $profile->driver_id
                || ! Driver::query()
                    ->whereKey($profile->driver_id)
                    ->exists()
            )
        ) {
            return 'complete_driver_profile';
        }

        return 'dashboard';
    }
}
