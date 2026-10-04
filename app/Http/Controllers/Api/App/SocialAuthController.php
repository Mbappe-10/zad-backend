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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocialAuthController extends Controller
{
    private const PWA_URL = 'https://zad-sync-sa.onrender.com';

    private const REDIRECT_CACHE_PREFIX = 'google.redirect.auth.';
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


    /**
     * Receives the Google Identity Services full-page redirect.
     *
     * The PWA and API currently use different Render origins, so the
     * application binds the redirect to a high-entropy state generated
     * by the PWA. The same state must be presented again when exchanging
     * the one-time code.
     */
    public function googleRedirect(Request $request): RedirectResponse
    {
        try {
            $data = $request->validate([
                'credential' => ['required', 'string'],
                'state' => [
                    'required',
                    'string',
                    'max:180',
                    'regex:/^(productive_family|driver)\.[A-Za-z0-9_-]{32,128}$/',
                ],
            ]);

            [$joinType] = explode('.', $data['state'], 2);

            /*
             * Verify the Google credential here so identity information
             * can safely travel through the one-time exchange.
             */
            $identity = $this->googleIdentity->verify(
                $data['credential'],
            );

            /*
             * Reuse the existing production Google authentication method.
             * This preserves all current account linking, role handling,
             * family/driver logic and next_step behavior.
             */
            $authRequest = Request::create(
                '/api/v1/app/auth/google',
                'POST',
                [
                    'id_token' => $data['credential'],
                    'join_type' => $joinType,
                    'device_name' => 'zad-pwa-google-redirect',
                ],
            );

            $authRequest->headers->set(
                'Accept',
                'application/json',
            );

            $authResponse = $this->google($authRequest);

            $payload = $authResponse->getData(true);

            if (! is_array($payload)) {
                throw new \RuntimeException(
                    'Invalid Google authentication response.',
                );
            }

            $payload['redirect_identity'] = [
                'email' => (string) ($identity['email'] ?? ''),
                'name' => trim(
                    (string) ($identity['name'] ?? ''),
                ),
                'photo_url' => filled($identity['picture'] ?? null)
                    ? (string) $identity['picture']
                    : null,
            ];

            /*
             * Never put the Sanctum token in the browser URL.
             * Only a random single-use code is returned to the PWA.
             */
            $code = Str::random(64);

            Cache::put(
                self::REDIRECT_CACHE_PREFIX.$code,
                [
                    'state' => $data['state'],
                    'payload' => $payload,
                ],
                now()->addMinutes(2),
            );

            return redirect()->away(
                self::PWA_URL
                .'/?google_auth_code='
                .rawurlencode($code)
                .'&google_auth_state='
                .rawurlencode($data['state']),
            );
        } catch (\Throwable $error) {
            report($error);

            return redirect()->away(
                self::PWA_URL
                .'/?google_auth_error=google_sign_in_failed',
            );
        }
    }

    /**
     * Exchanges the short-lived redirect code for the normal ZAD auth
     * response. Cache::pull makes the code single-use.
     */
    public function exchangeGoogleRedirect(
        Request $request,
    ): JsonResponse {
        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'size:64',
            ],
            'state' => [
                'required',
                'string',
                'max:180',
                'regex:/^(productive_family|driver)\.[A-Za-z0-9_-]{32,128}$/',
            ],
        ]);

        $cached = Cache::pull(
            self::REDIRECT_CACHE_PREFIX.$data['code'],
        );

        if (
            ! is_array($cached)
            || ! isset($cached['state'])
            || ! isset($cached['payload'])
            || ! is_array($cached['payload'])
        ) {
            return response()->json([
                'message' =>
                    'انتهت جلسة تسجيل Google أو تم استخدامها مسبقًا. أعد تسجيل الدخول.',
            ], 422);
        }

        $storedState = (string) $cached['state'];

        if (! hash_equals($storedState, $data['state'])) {
            return response()->json([
                'message' =>
                    'تعذر التحقق من جلسة تسجيل Google. أعد المحاولة.',
            ], 422);
        }

        return response()->json(
            $cached['payload'],
        );
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
