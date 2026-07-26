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
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $email = Str::lower(trim($validated['email']));

        $throttleKey = sprintf(
            'login:%s|%s',
            $email,
            $request->ip(),
        );

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => [
                    'تم تجاوز عدد محاولات تسجيل الدخول. حاول مرة أخرى بعد دقيقة.',
                ],
            ]);
        }

        /** @var User|null $user */
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (
            !$user ||
            !Hash::check($validated['password'], $user->password) ||
            $user->status !== 'active' ||
            !$user->is_approved
        ) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => [
                    'بيانات الدخول غير صحيحة أو أن الحساب غير نشط.',
                ],
            ]);
        }

        RateLimiter::clear($throttleKey);

        /*
         * حذف التوكنات القديمة الخاصة بلوحة التحكم.
         * يمنع تراكم التوكنات عند تكرار تسجيل الدخول.
         */
        $user->tokens()
            ->where('name', 'zad-admin-dashboard')
            ->delete();

        $abilities = $user->isPlatformOwner()
            ? ['*']
            : ['dashboard:access'];

        $expiration = ($validated['remember'] ?? false)
            ? now()->addDays(30)
            : now()->addHours(12);

        $token = $user->createToken(
            name: 'zad-admin-dashboard',
            abilities: $abilities,
            expiresAt: $expiration,
        );

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return response()->json([
            'message' => 'تم تسجيل الدخول بنجاح.',
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiration->toISOString(),
            'user' => $this->serializeUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->serializeUser($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'تم تسجيل الخروج بنجاح.',
        ]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->tokens()->delete();

        return response()->json([
            'message' => 'تم تسجيل الخروج من جميع الأجهزة بنجاح.',
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'locale' => ['required', 'in:ar,en'],
            'timezone' => ['required', 'timezone'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $user->update($validated);

        return response()->json([
            'message' => 'تم تحديث الملف الشخصي بنجاح.',
            'user' => $this->serializeUser($user->fresh()),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
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

        if (!Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [
                    'كلمة المرور الحالية غير صحيحة.',
                ],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
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

    public function uploadProfilePhoto(Request $request): JsonResponse
    {
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

        $this->deleteStoredProfilePhoto($user->profile_photo);

        $path = $request
            ->file('photo')
            ->store('profile-photos', 'public');

        $user->update([
            'profile_photo' => Storage::disk('public')->url($path),
        ]);

        return response()->json([
            'message' => 'تم تحديث الصورة الشخصية بنجاح.',
            'user' => $this->serializeUser($user->fresh()),
        ], 201);
    }

    public function removeProfilePhoto(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->deleteStoredProfilePhoto($user->profile_photo);

        $user->update([
            'profile_photo' => null,
        ]);

        return response()->json([
            'message' => 'تم حذف الصورة الشخصية.',
            'user' => $this->serializeUser($user->fresh()),
        ]);
    }

    /**
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

        $rolePermissions = $user
            ->roles
            ->flatMap(fn ($role) => $role->permissions)
            ->pluck('key');

        $allowedDirectPermissions = $user
            ->directPermissions
            ->filter(
                fn ($permission) =>
                    $permission->pivot->effect === 'allow'
                    && (
                        $permission->pivot->expires_at === null
                        || now()->lessThan($permission->pivot->expires_at)
                    ),
            )
            ->pluck('key');

        $deniedDirectPermissions = $user
            ->directPermissions
            ->filter(
                fn ($permission) =>
                    $permission->pivot->effect === 'deny'
                    && (
                        $permission->pivot->expires_at === null
                        || now()->lessThan($permission->pivot->expires_at)
                    ),
            )
            ->pluck('key');

        $permissions = $rolePermissions
            ->merge($allowedDirectPermissions)
            ->unique()
            ->reject(
                fn (string $permission) =>
                    $deniedDirectPermissions->contains($permission),
            )
            ->values();

        return [
            'id' => $user->id,
            'nameAr' => $user->name_ar ?: $user->name,
            'nameEn' => $user->name_en ?: $user->name,
            'email' => $user->email,
            'phone' => $user->phone,

            'role' => $user->roles
                ->sortByDesc('priority')
                ->first()?->key ?? 'candidate',

            'roles' => $user->roles
                ->map(fn ($role) => [
                    'id' => $role->id,
                    'key' => $role->key,
                    'nameAr' => $role->name_ar,
                    'nameEn' => $role->name_en,
                ])
                ->values(),

            'accountType' => 'human',
            'permissions' => $permissions,
            'profilePhoto' => $user->profile_photo,
            'isApproved' => $user->is_approved,
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

    private function deleteStoredProfilePhoto(?string $profilePhoto): void
    {
        if (!$profilePhoto) {
            return;
        }

        $path = parse_url($profilePhoto, PHP_URL_PATH);

        if (
            !is_string($path) ||
            !Str::startsWith($path, '/storage/profile-photos/')
        ) {
            return;
        }

        Storage::disk('public')->delete(
            Str::after($path, '/storage/'),
        );
    }
}