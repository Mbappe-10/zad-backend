<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'name_ar',
        'name_en',
        'email',
        'phone',
        'password',
        'auth_provider',
        'provider_user_id',
        'profile_photo',
        'department_id',
        'job_title_id',
        'manager_id',
        'status',
        'is_approved',
        'approved_by',
        'approved_at',
        'suspended_at',
        'suspension_reason',
        'locale',
        'timezone',
        'last_login_at',
        'last_login_ip',
        'mfa_enabled',
        'password_changed_at',
        'is_platform_owner',
        'is_protected',
        'role_locked',
        'permissions_locked',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_approved' => 'boolean',
            'mfa_enabled' => 'boolean',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'is_platform_owner' => 'boolean',
            'is_protected' => 'boolean',
            'role_locked' => 'boolean',
            'permissions_locked' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | القسم والمسمى الوظيفي
    |--------------------------------------------------------------------------
    */

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    /*
    |--------------------------------------------------------------------------
    | الهيكل الإداري
    |--------------------------------------------------------------------------
    */

    public function manager(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'manager_id',
        );
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(
            User::class,
            'manager_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | الاعتماد
    |--------------------------------------------------------------------------
    */

    public function approver(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'approved_by',
        );
    }

    public function approvedUsers(): HasMany
    {
        return $this->hasMany(
            User::class,
            'approved_by',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | الأدوار
    |--------------------------------------------------------------------------
    */

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_user',
        )
            ->withPivot([
                'assigned_by',
                'expires_at',
            ])
            ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | صلاحيات المستخدم الخاصة
    |--------------------------------------------------------------------------
    */

    public function directPermissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'user_permissions',
        )
            ->withPivot([
                'effect',
                'granted_by',
                'reason',
                'expires_at',
            ])
            ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | حالة المستخدم
    |--------------------------------------------------------------------------
    */

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isApproved(): bool
    {
        return $this->is_approved === true;
    }

    public function displayName(string $locale = 'ar'): string
    {
        if ($locale === 'ar') {
            return $this->name_ar
                ?: $this->name
                ?: $this->name_en
                ?: $this->email
                ?: 'مستخدم زاد';
        }

        return $this->name_en
            ?: $this->name
            ?: $this->name_ar
            ?: $this->email
            ?: 'ZAD User';
    }

    /*
    |--------------------------------------------------------------------------
    | التحقق من الأدوار
    |--------------------------------------------------------------------------
    */

    public function hasRole(string $roleName): bool
    {
        $this->loadMissing('roles');

        return $this->roles->contains(
            function (Role $role) use ($roleName): bool {
                if ($this->roleIsExpired($role)) {
                    return false;
                }

                return $this->normalizeIdentifier(
                    $this->roleIdentifier($role),
                ) === $this->normalizeIdentifier($roleName);
            },
        );
    }

    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole((string) $role)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllRoles(array $roles): bool
    {
        foreach ($roles as $role) {
            if (! $this->hasRole((string) $role)) {
                return false;
            }
        }

        return true;
    }

    public function isPlatformOwner(): bool
    {
        return (bool) $this->is_platform_owner;
    }

    /**
     * هل يستطيع المستخدم عرض إعدادات حقول المنتجات؟
     *
     * مالك المنصة يستطيع العرض دائمًا.
     */
    public function canViewProductFields(): bool
    {
        return $this->isPlatformOwner()
            || $this->hasPermission('products.fields.view')
            || $this->hasPermission('products.fields.manage');
    }

    /**
     * هل يستطيع المستخدم تعديل إعدادات حقول المنتجات؟
     *
     * مالك المنصة يمتلك هذه الصلاحية دائمًا حتى لو لم تكن
     * مسجلة كسجل مستقل في جدول permissions.
     */
    public function canManageProductFields(): bool
    {
        return $this->isPlatformOwner()
            || $this->hasPermission('products.fields.manage');
    }

    /*
    |--------------------------------------------------------------------------
    | التحقق من الصلاحيات
    |--------------------------------------------------------------------------
    */

    public function hasPermission(string $permissionName): bool
    {
        /*
        |--------------------------------------------------------------------------
        | مالك المنصة يمتلك جميع الصلاحيات
        |--------------------------------------------------------------------------
        */

        if ($this->isPlatformOwner()) {
            return true;
        }

        $this->loadMissing([
            'roles.permissions',
            'directPermissions',
        ]);

        /*
        |--------------------------------------------------------------------------
        | المنع المباشر يتغلب على السماح المباشر وصلاحيات الأدوار
        |--------------------------------------------------------------------------
        */

        $directDenied = $this->directPermissions->contains(
            function (
                Permission $permission,
            ) use ($permissionName): bool {
                if ($this->directPermissionIsExpired($permission)) {
                    return false;
                }

                return $this->permissionMatches(
                    $permission,
                    $permissionName,
                ) && $permission->pivot?->effect === 'deny';
            },
        );

        if ($directDenied) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | السماح المباشر
        |--------------------------------------------------------------------------
        */

        $directAllowed = $this->directPermissions->contains(
            function (
                Permission $permission,
            ) use ($permissionName): bool {
                if ($this->directPermissionIsExpired($permission)) {
                    return false;
                }

                return $this->permissionMatches(
                    $permission,
                    $permissionName,
                ) && $permission->pivot?->effect === 'allow';
            },
        );

        if ($directAllowed) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | صلاحيات الأدوار
        |--------------------------------------------------------------------------
        */

        foreach ($this->roles as $role) {
            if ($this->roleIsExpired($role)) {
                continue;
            }

            $hasPermission = $role->permissions->contains(
                fn (Permission $permission): bool => $this->permissionMatches(
                    $permission,
                    $permissionName,
                ),
            );

            if ($hasPermission) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission((string) $permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermission((string) $permission)) {
                return false;
            }
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | الصلاحيات الفعلية النهائية للمستخدم
    |--------------------------------------------------------------------------
    */

    public function effectivePermissions(): array
    {
        $this->loadMissing([
            'roles.permissions',
            'directPermissions',
        ]);

        /*
        |--------------------------------------------------------------------------
        | مالك المنصة يحصل على جميع صلاحيات النظام
        |--------------------------------------------------------------------------
        */

        if ($this->isPlatformOwner()) {
            return Permission::query()
                ->get()
                ->map(
                    fn (Permission $permission): string => $this->permissionIdentifier($permission),
                )
                ->filter()
                ->merge([
                    'products.fields.view',
                    'products.fields.manage',
                ])
                ->unique(
                    fn (string $permission): string => $this->normalizeIdentifier($permission),
                )
                ->values()
                ->all();
        }

        $permissions = collect();

        /*
        |--------------------------------------------------------------------------
        | جمع صلاحيات الأدوار
        |--------------------------------------------------------------------------
        */

        foreach ($this->roles as $role) {
            if ($this->roleIsExpired($role)) {
                continue;
            }

            foreach ($role->permissions as $permission) {
                $identifier = $this->permissionIdentifier($permission);

                if ($identifier !== '') {
                    $permissions->push($identifier);
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | تطبيق صلاحيات المستخدم المباشرة
        |--------------------------------------------------------------------------
        */

        foreach ($this->directPermissions as $permission) {
            if ($this->directPermissionIsExpired($permission)) {
                continue;
            }

            $identifier = $this->permissionIdentifier($permission);

            if ($identifier === '') {
                continue;
            }

            if ($permission->pivot?->effect === 'deny') {
                $permissions = $permissions->reject(
                    fn (string $item): bool => $this->normalizeIdentifier($item)
                        === $this->normalizeIdentifier($identifier),
                );

                continue;
            }

            if ($permission->pivot?->effect === 'allow') {
                $permissions->push($identifier);
            }
        }

        return $permissions
            ->filter()
            ->unique(
                fn (string $permission): string => $this->normalizeIdentifier($permission),
            )
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | أدوات داخلية للأدوار والصلاحيات
    |--------------------------------------------------------------------------
    */

    private function permissionMatches(
        Permission $permission,
        string $permissionName,
    ): bool {
        return $this->normalizeIdentifier(
            $this->permissionIdentifier($permission),
        ) === $this->normalizeIdentifier($permissionName);
    }

    private function permissionIdentifier(
        Permission $permission,
    ): string {
        return trim(
            (string) (
                $permission->slug
                ?? $permission->key
                ?? $permission->code
                ?? $permission->name
                ?? ''
            ),
        );
    }

    private function roleIdentifier(Role $role): string
    {
        return trim(
            (string) (
                $role->slug
                ?? $role->key
                ?? $role->code
                ?? $role->name
                ?? ''
            ),
        );
    }

    private function normalizeIdentifier(string $value): string
    {
        return strtolower(trim($value));
    }

    private function roleIsExpired(Role $role): bool
    {
        $expiresAt = $role->pivot?->expires_at;

        if (! $expiresAt) {
            return false;
        }

        return now()->greaterThan($expiresAt);
    }

    private function directPermissionIsExpired(
        Permission $permission,
    ): bool {
        $expiresAt = $permission->pivot?->expires_at;

        if (! $expiresAt) {
            return false;
        }

        return now()->greaterThan($expiresAt);
    }
}