<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\AppProfile;
use App\Models\Driver;
use App\Models\ProductiveFamily;
use App\Services\App\GoogleIdentityVerifier;
use App\Services\App\SocialAccountLinker;
use App\Services\LaunchAttributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SocialAuthController extends Controller
{
    public function __construct(
        private readonly GoogleIdentityVerifier $googleIdentity,
        private readonly SocialAccountLinker $accounts,
        private readonly LaunchAttributionService $launchAttribution,
    ) {
    }

    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string'],
            'join_type' => [
                'required',
                'in:productive_family,driver',
            ],
            'device_name' => [
                'nullable',
                'string',
                'max:100',
            ],
            'launch_session_token' => ['nullable', 'string', 'max:128'],
            'guest_session_id' => ['nullable', 'uuid', 'exists:app_guest_sessions,id'],
        ]);

        $payload = $this->googleIdentity->verify(
            $data['id_token'],
        );

        $providerUserId = (string) $payload['sub'];
        $email = (string) $payload['email'];
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            $name = 'مستخدم زاد سينك';
        }

        [$user, $profile, $isNewUser, $accountLinked] =
            DB::transaction(function () use (
                $providerUserId,
                $email,
                $name,
                $data,
            ): array {
                [$user, $isNewUser] =
                    $this->accounts->resolveGoogleUser(
                        $providerUserId,
                        $email,
                        $name,
                        $data['join_type'],
                    );

                $profile = AppProfile::query()->firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'roles' => [],
                        'active_mode' => $data['join_type'],
                    ],
                );

                $roles = is_array($profile->roles)
                    ? $profile->roles
                    : [];

                if (! in_array($data['join_type'], $roles, true)) {
                    $roles[] = $data['join_type'];
                }

                $profile->forceFill([
                    'roles' => array_values(array_unique($roles)),
                    'active_mode' => $data['join_type'],
                ])->save();

                $accountLinked =
                    $this->accounts->linkRoleAccount(
                        $user,
                        $profile,
                        $email,
                        $data['join_type'],
                        $name,
                    );

                return [
                    $user->fresh(),
                    $profile->fresh(),
                    $isNewUser,
                    $accountLinked,
                ];
            });

        $token = $user
            ->createToken(
                $data['device_name'] ?? 'zad-mobile-app',
            )
            ->plainTextToken;

        if (filled($data['launch_session_token'] ?? null)) {
            $this->launchAttribution->claim(
                $data['launch_session_token'],
                (int) $user->id,
                $data['guest_session_id'] ?? null,
            );
        }

        return response()->json([
            'message' => $accountLinked
                ? 'تم تسجيل الدخول وربط الحساب التشغيلي بنجاح.'
                : ($isNewUser
                    ? 'تم إنشاء حساب زاد سينك بنجاح.'
                    : 'تم تسجيل الدخول بنجاح.'),
            'is_new_user' => $isNewUser,
            'account_linked' => $accountLinked,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->displayName('ar'),
                'auth_provider' => $user->auth_provider,
                'roles' => $profile->roles ?? [],
                'active_mode' => $profile->active_mode,
                'productive_family_id' =>
                    $profile->productive_family_id,
                'driver_id' => $profile->driver_id,
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
        if ($activeMode === 'productive_family') {
            $familyExists = $profile->productive_family_id !== null
                && ProductiveFamily::query()
                    ->whereKey($profile->productive_family_id)
                    ->whereHas('store')
                    ->exists();

            return $familyExists
                ? 'dashboard'
                : 'complete_productive_family_profile';
        }

        if ($activeMode === 'driver') {
            $driver = $profile->driver_id !== null
                ? Driver::query()->find($profile->driver_id)
                : null;

            if ($driver === null) {
                return 'complete_driver_profile';
            }

            return match ($driver->application_status) {
                Driver::APPLICATION_APPROVED => 'dashboard',
                Driver::APPLICATION_REJECTED,
                'needs_correction' => 'driver_profile_rejected',
                default => 'driver_pending_review',
            };
        }

        return 'dashboard';
    }
}
