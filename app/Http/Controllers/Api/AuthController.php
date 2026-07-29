<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * تسجيل الدخول باستخدام Sanctum Bearer Token.
     *
     * هذا المسار لا يعتمد على:
     * - جلسات Laravel
     * - CSRF Cookie
     * - XSRF-TOKEN
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
            ],

            'password' => [
                'required',
                'string',
                'max:255',
            ],

            'remember' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $email = Str::lower(
            trim((string) $validated['email'])
        );

        $throttleKey = sprintf(
            'admin-login:%s|%s',
            $email,
            $request->ip() ?? 'unknown',
        );

        /*
         |--------------------------------------------------------------------------
         | حماية تسجيل الدخول من المحاولات المتكررة
         |--------------------------------------------------------------------------
         */

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => [
                    "تم تجاوز عدد محاولات تسجيل الدخول. حاول مجددًا بعد {$seconds} ثانية.",
                ],
            ]);
        }

        /*
         |--------------------------------------------------------------------------
         | البحث عن المستخدم
         |--------------------------------------------------------------------------
         */

        /** @var User|null $user */
        $user = User::query()
            ->withTrashed()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        /*
         |--------------------------------------------------------------------------
         | التحقق من كلمة المرور
         |--------------------------------------------------------------------------
         */

        if (
            ! $user ||
            $user->trashed() ||
            ! Hash::check(
                (string) $validated['password'],
                $user->password,
            )
        ) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => [
                    'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
                ],
            ]);
        }

        /*
         |--------------------------------------------------------------------------
         | التحقق من حالة الحساب
         |--------------------------------------------------------------------------
         */

        if ($user->status !== 'active') {
            RateLimiter::hit($throttleKey, 60);

            return response()->json([
                'message' => 'الحساب غير نشط حاليًا.',
                'code' => 'ACCOUNT_INACTIVE',
            ], 403);
        }

        if (! $user->is_approved) {
            RateLimiter::hit($throttleKey, 60);

            return response()->json([
                'message' => 'الحساب لم يتم اعتماده بعد.',
                'code' => 'ACCOUNT_NOT_APPROVED',
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        /*
         |--------------------------------------------------------------------------
         | حذف توكنات لوحة التحكم السابقة
         |--------------------------------------------------------------------------
         |
         | يمنع تراكم توكنات قديمة لنفس المستخدم.
         |
         */

        $user->tokens()
            ->where('name', 'zad-admin-dashboard')
            ->delete();

        /*
         |--------------------------------------------------------------------------
         | صلاحيات التوكن
         |--------------------------------------------------------------------------
         */

        $abilities = $user->isPlatformOwner()
            ? ['*']
            : ['dashboard:access'];

        /*
         |--------------------------------------------------------------------------
         | مدة صلاحية التوكن
         |--------------------------------------------------------------------------
         */

        $remember = (bool) ($validated['remember'] ?? false);

        $expiration = $remember
            ? now()->addDays(30)
            : now()->addHours(12);

        /*
         |--------------------------------------------------------------------------
         | إنشاء Bearer Token
         |--------------------------------------------------------------------------
         */

        $newToken = $user->createToken(
            name: 'zad-admin-dashboard',
            abilities: $abilities,
            expiresAt: $expiration,
        );

        /*
         |--------------------------------------------------------------------------
         | تحديث معلومات آخر دخول
         |--------------------------------------------------------------------------
         */

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $user->refresh();

        /*
         |--------------------------------------------------------------------------
         | استجابة تسجيل الدخول
         |--------------------------------------------------------------------------
         */

        return response()->json([
            'message' => 'تم تسجيل الدخول بنجاح.',

            /*
             * نعيد الاسمين لضمان التوافق مع الواجهة الحالية.
             */
            'token' => $newToken->plainTextToken,
            'access_token' => $newToken->plainTextToken,

            'token_type' => 'Bearer',
            'expires_at' => $expiration->toISOString(),

            'user' => $this->serializeUser($user),
        ]);
    }

    /**
     * بيانات المستخدم الحالي.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'المستخدم غير مسجل الدخول.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        if (
            $user->status !== 'active' ||
            ! $user->is_approved
        ) {
            $request->user()
                ?->currentAccessToken()
                ?->delete();

            return response()->json([
                'message' => 'الحساب غير نشط أو غير معتمد.',
                'code' => 'ACCOUNT_UNAVAILABLE',
            ], 403);
        }

        return response()->json([
            'user' => $this->serializeUser($user),
        ]);
    }

    /**
     * تسجيل الخروج من الجهاز الحالي.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()
            ?->currentAccessToken()
            ?->delete();

        return response()->json([
            'message' => 'تم تسجيل الخروج بنجاح.',
        ]);
    }

    /**
     * تسجيل الخروج من جميع الأجهزة.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'المستخدم غير مسجل الدخول.',
            ], 401);
        }

        $user->tokens()->delete();

        return response()->json([
            'message' => 'تم تسجيل الخروج من جميع الأجهزة بنجاح.',
        ]);
    }

    /**
     * تحديث الملف الشخصي.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name_ar' => [
                'required',
                'string',
                'max:120',
            ],

            'name_en' => [
                'required',
                'string',
                'max:120',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'locale' => [
                'required',
                'in:ar,en',
            ],

            'timezone' => [
                'required',
                'timezone',
            ],
        ]);

        /** @var User $user */
        $user = $request->user();

        $user->update($validated);

        return response()->json([
            'message' => 'تم تحديث الملف الشخصي بنجاح.',
            'user' => $this->serializeUser(
                $user->fresh(),
            ),
        ]);
    }

    /**
     * تغيير كلمة المرور.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => [
                'required',
                'string',
            ],

            'password' => [
                'required',
                'confirmed',

                Password::min(10)
                    ->mixedCase()
                    ->numbers(),
            ],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (
            ! Hash::check(
                $validated['current_password'],
                $user->password,
            )
        ) {
            throw ValidationException::withMessages([
                'current_password' => [
                    'كلمة المرور الحالية غير صحيحة.',
                ],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make(
                $validated['password'],
            ),

            'password_changed_at' => now(),

            'remember_token' => Str::random(60),
        ])->save();

        /*
         * إبطال جميع التوكنات بعد تغيير كلمة المرور.
         */
        $user->tokens()->delete();

        return response()->json([
            'message' => 'تم تغيير كلمة المرور بنجاح. يرجى تسجيل الدخول مجددًا.',
        ]);
    }

    /**
     * رفع الصورة الشخصية.
     */
    public function uploadProfilePhoto(
        Request $request,
    ): JsonResponse {
        $request->validate([
            'photo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
        ]);

        /** @var User $user */
        $user = $request->user();

        $this->deleteStoredProfilePhoto(
            $user->profile_photo,
        );

        $path = $request
            ->file('photo')
            ->store(
                'profile-photos',
                'public',
            );

        $user->update([
            'profile_photo' => Storage::disk('public')
                ->url($path),
        ]);

        return response()->json([
            'message' => 'تم تحديث الصورة الشخصية بنجاح.',
            'user' => $this->serializeUser(
                $user->fresh(),
            ),
        ], 201);
    }

    /**
     * حذف الصورة الشخصية.
     */
    public function removeProfilePhoto(
        Request $request,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $this->deleteStoredProfilePhoto(
            $user->profile_photo,
        );

        $user->update([
            'profile_photo' => null,
        ]);

        return response()->json([
            'message' => 'تم حذف الصورة الشخصية.',
            'user' => $this->serializeUser(
                $user->fresh(),
            ),
        ]);
    }

    /**
     * تجهيز بيانات المستخدم للواجهة.
     *
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        $user->loadMissing([
            'department',
            'jobTitle',
            'roles.permissions',
            'directPermissions',
        ]);

        /*
         |--------------------------------------------------------------------------
         | مالك المنصة
         |--------------------------------------------------------------------------
         */

        if ($user->isPlatformOwner()) {
            $permissions = collect(
                $user->effectivePermissions(),
            );
        } else {
            /*
             |--------------------------------------------------------------------------
             | صلاحيات الأدوار
             |--------------------------------------------------------------------------
             */

            $rolePermissions = $user
                ->roles
                ->flatMap(
                    fn ($role) => $role->permissions,
                )
                ->map(
                    fn ($permission) => $permission->key
                        ?? $permission->slug
                        ?? $permission->code
                        ?? $permission->name,
                )
                ->filter();

            /*
             |--------------------------------------------------------------------------
             | الصلاحيات المباشرة المسموحة
             |--------------------------------------------------------------------------
             */

            $allowedDirectPermissions = $user
                ->directPermissions
                ->filter(
                    fn ($permission) => $permission->pivot?->effect === 'allow'
                        && (
                            $permission->pivot?->expires_at === null
                            || now()->lessThan(
                                $permission->pivot->expires_at,
                            )
                        ),
                )
                ->map(
                    fn ($permission) => $permission->key
                        ?? $permission->slug
                        ?? $permission->code
                        ?? $permission->name,
                )
                ->filter();

            /*
             |--------------------------------------------------------------------------
             | الصلاحيات المباشرة الممنوعة
             |--------------------------------------------------------------------------
             */

            $deniedDirectPermissions = $user
                ->directPermissions
                ->filter(
                    fn ($permission) => $permission->pivot?->effect === 'deny'
                        && (
                            $permission->pivot?->expires_at === null
                            || now()->lessThan(
                                $permission->pivot->expires_at,
                            )
                        ),
                )
                ->map(
                    fn ($permission) => $permission->key
                        ?? $permission->slug
                        ?? $permission->code
                        ?? $permission->name,
                )
                ->filter();

            $permissions = $rolePermissions
                ->merge($allowedDirectPermissions)
                ->unique()
                ->reject(
                    fn ($permission) => $deniedDirectPermissions
                        ->contains($permission),
                )
                ->values();
        }

        $primaryRole = $user
            ->roles
            ->sortByDesc(
                fn ($role) => $role->priority ?? 0,
            )
            ->first();

        return [
            'id' => $user->id,

            'name' => $user->displayName(
                $user->locale ?? 'ar',
            ),

            'nameAr' => $user->name_ar
                ?: $user->name,

            'nameEn' => $user->name_en
                ?: $user->name,

            'email' => $user->email,
            'phone' => $user->phone,

            'role' => $primaryRole
                ? (
                    $primaryRole->key
                    ?? $primaryRole->slug
                    ?? $primaryRole->code
                    ?? 'candidate'
                )
                : 'candidate',

            'roles' => $user->roles
                ->map(
                    fn ($role) => [
                        'id' => $role->id,

                        'key' => $role->key
                            ?? $role->slug
                            ?? $role->code,

                        'nameAr' => $role->name_ar
                            ?? $role->name,

                        'nameEn' => $role->name_en
                            ?? $role->name,
                    ],
                )
                ->values(),

            'accountType' => 'human',

            'permissions' => $permissions
                ->values()
                ->all(),

            'profilePhoto' => $user->profile_photo,

            'isApproved' => (bool) $user->is_approved,
            'status' => $user->status,
            'locale' => $user->locale,
            'timezone' => $user->timezone,

            'department' => $user->department
                ? [
                    'id' => $user->department->id,
                    'code' => $user->department->code,
                    'nameAr' => $user->department->name_ar,
                    'nameEn' => $user->department->name_en,
                ]
                : null,

            'jobTitle' => $user->jobTitle
                ? [
                    'id' => $user->jobTitle->id,
                    'code' => $user->jobTitle->code,
                    'nameAr' => $user->jobTitle->name_ar,
                    'nameEn' => $user->jobTitle->name_en,
                ]
                : null,

            'lastLoginAt' => optional(
                $user->last_login_at,
            )?->toISOString(),
        ];
    }

    /**
     * حذف الصورة القديمة من التخزين المحلي.
     */
    private function deleteStoredProfilePhoto(
        ?string $profilePhoto,
    ): void {
        if (! $profilePhoto) {
            return;
        }

        $path = parse_url(
            $profilePhoto,
            PHP_URL_PATH,
        );

        if (
            ! is_string($path) ||
            ! Str::startsWith(
                $path,
                '/storage/profile-photos/',
            )
        ) {
            return;
        }

        Storage::disk('public')->delete(
            Str::after(
                $path,
                '/storage/',
            ),
        );
    }
}
