<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Models\PlatformSetting;
use App\Models\PlatformSettingAudit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PlatformSettingsController extends Controller
{
    /**
     * المفاتيح التي تعتبر بيانات حساسة ولا ينبغي إظهار قيمتها كاملة.
     */
    private const SENSITIVE_KEYWORDS = [
        'secret',
        'token',
        'password',
        'private_key',
        'privatekey',
        'api_key',
        'apikey',
        'access_key',
        'accesskey',
        'client_secret',
        'clientsecret',
        'webhook_secret',
    ];

    /**
     * الصلاحيات التي تسمح بعرض صفحة الإعدادات.
     */
    private const VIEW_PERMISSIONS = [
        'settings.view',
        'settings.manage',
        'settings.approve',
        'settings.publish',
        'settings.executive.view',
        'general_management.settings.view',
        'general_management.settings.manage',
        'general_management.settings.approve',
        'master_settings.access',
    ];

    /**
     * الصلاحيات التي تسمح بتعديل الإعدادات.
     */
    private const MANAGE_PERMISSIONS = [
        'settings.manage',
        'settings.executive.manage',
        'settings.governance.manage',
        'settings.ai.manage',
        'settings.branding.manage',
        'general_management.settings.manage',
        'master_settings.access',
    ];

    /**
     * الأقسام التنفيذية التي لا تظهر إلا للمالك أو الإدارة العامة.
     */
    private const EXECUTIVE_GROUPS = [
        'executiveManagement',
        'generalManagement',
        'strategicPlanning',
        'riskManagement',
        'organizationStructure',
        'executiveGovernance',
    ];

    /**
     * الأقسام الحساسة التي تحتاج صلاحية إدارية عليا.
     */
    private const SENSITIVE_GROUPS = [
        'security',
        'governance',
        'aiControl',
        'storage',
        'finance',
        'services',
        'integrations',
        'executiveManagement',
        'generalManagement',
    ];

    /**
     * عرض إعدادات المنصة.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user && $this->canViewSettings($user),
            403,
            'ليس لديك صلاحية لعرض إعدادات المنصة.',
        );

        $permissions = $this->getUserPermissions($user);
        $isOwner = $this->isPlatformOwner($user);
        $isGeneralManagement = $this->isGeneralManagementUser(
            $user,
            $permissions,
        );

        $settings = $this->currentSettings();

        /*
         * إخفاء أقسام الإدارة العليا عمّن لا يملك صلاحيتها.
         */
        if (! $isOwner && ! $isGeneralManagement) {
            foreach (self::EXECUTIVE_GROUPS as $group) {
                unset($settings[$group]);
            }
        }

        /*
         * إخفاء القيم الحساسة مع الإبقاء على معرفة أنها محفوظة.
         */
        $settings = $this->maskSensitiveSettings($settings);

        return response()->json([
            'data' => $settings,

            'meta' => [
                'isOwner' => $isOwner,

                'isGeneralManagement' => $isGeneralManagement,

                'permissions' => $permissions,

                'canView' => true,

                'canManage' => $this->canManageSettings(
                    $user,
                    $permissions,
                ),

                'canApprove' => $isOwner || $this->hasAnyPermission(
                    $permissions,
                    [
                        'settings.approve',
                        'general_management.settings.approve',
                    ],
                ),

                'canPublish' => $isOwner || $this->hasAnyPermission(
                    $permissions,
                    [
                        'settings.publish',
                        'general_management.settings.approve',
                    ],
                ),

                'canRollback' => $isOwner || in_array(
                    'settings.rollback',
                    $permissions,
                    true,
                ),

                'canAccessExecutiveSettings' => $isOwner
                    || $isGeneralManagement,

                'canAccessMasterCenter' => $isOwner
                    || in_array(
                        'master_settings.access',
                        $permissions,
                        true,
                    ),

                'groupsCount' => count($settings),

                'lastUpdatedAt' => PlatformSetting::query()
                    ->max('updated_at'),

                'generatedAt' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * بيانات الصلاحيات الخاصة بصفحة الإعدادات.
     */
    public function meta(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user,
            401,
            'يجب تسجيل الدخول أولًا.',
        );

        $permissions = $this->getUserPermissions($user);
        $isOwner = $this->isPlatformOwner($user);
        $isGeneralManagement = $this->isGeneralManagementUser(
            $user,
            $permissions,
        );

        return response()->json([
            'meta' => [
                'isOwner' => $isOwner,

                'isGeneralManagement' => $isGeneralManagement,

                'permissions' => $permissions,

                'canView' => $this->canViewSettings($user),

                'canManage' => $this->canManageSettings(
                    $user,
                    $permissions,
                ),

                'canApprove' => $isOwner || $this->hasAnyPermission(
                    $permissions,
                    [
                        'settings.approve',
                        'general_management.settings.approve',
                    ],
                ),

                'canPublish' => $isOwner || $this->hasAnyPermission(
                    $permissions,
                    [
                        'settings.publish',
                        'general_management.settings.approve',
                    ],
                ),

                'canRollback' => $isOwner || in_array(
                    'settings.rollback',
                    $permissions,
                    true,
                ),

                'canAccessExecutiveSettings' => $isOwner
                    || $isGeneralManagement,

                'canAccessMasterCenter' => $isOwner
                    || in_array(
                        'master_settings.access',
                        $permissions,
                        true,
                    ),
            ],
        ]);
    }

    /**
     * تحديث إعدادات المنصة.
     */
    public function update(
        UpdatePlatformSettingsRequest $request,
    ): JsonResponse {
        $user = $request->user();
        $permissions = $this->getUserPermissions($user);
        $isOwner = $this->isPlatformOwner($user);
        $isGeneralManagement = $this->isGeneralManagementUser(
            $user,
            $permissions,
        );

        abort_unless(
            $this->canManageSettings($user, $permissions),
            403,
            'ليس لديك صلاحية لتعديل إعدادات المنصة.',
        );

        $settings = $request->validated('settings');

        if (! is_array($settings)) {
            return response()->json([
                'message' => 'صيغة الإعدادات المرسلة غير صحيحة.',
            ], 422);
        }

        $reason = trim(
            (string) $request->input('reason', ''),
        );

        /*
         * منع غير الإدارة العليا من تعديل أقسام الإدارة العامة.
         */
        foreach (array_keys($settings) as $group) {
            if (
                in_array($group, self::EXECUTIVE_GROUPS, true)
                && ! $isOwner
                && ! $isGeneralManagement
            ) {
                return response()->json([
                    'message' => 'لا تملك صلاحية تعديل إعدادات الإدارة العامة.',
                    'errors' => [
                        $group => [
                            'هذا القسم خاص بمالك المنصة والإدارة العامة.',
                        ],
                    ],
                ], 403);
            }

            /*
             * الأقسام الحساسة تتطلب سببًا للتغيير.
             */
            if (
                in_array($group, self::SENSITIVE_GROUPS, true)
                && $reason === ''
            ) {
                return response()->json([
                    'message' => 'يجب كتابة سبب عند تعديل قسم حساس.',
                    'errors' => [
                        'reason' => [
                            'سبب التعديل مطلوب للأقسام الحساسة.',
                        ],
                    ],
                ], 422);
            }
        }

        $changedCount = 0;
        $unchangedCount = 0;

        DB::transaction(function () use (
            $settings,
            $user,
            $request,
            $reason,
            &$changedCount,
            &$unchangedCount,
        ): void {
            foreach ($settings as $group => $values) {
                if (! is_array($values)) {
                    continue;
                }

                foreach ($values as $key => $value) {
                    $group = $this->sanitizeIdentifier(
                        (string) $group,
                        100,
                    );

                    $key = $this->sanitizeIdentifier(
                        (string) $key,
                        150,
                    );

                    if ($group === '' || $key === '') {
                        continue;
                    }

                    $existing = PlatformSetting::query()
                        ->where('group', $group)
                        ->where('key', $key)
                        ->lockForUpdate()
                        ->first();

                    $oldValue = $existing?->value['value'] ?? null;

                    /*
                     * عند إرسال قيمة مخفية مثل •••••••• لا يتم استبدال
                     * المفتاح السري الحقيقي بالقيمة المخفية.
                     */
                    if (
                        $this->isSensitiveKey($key)
                        && $this->isMaskedValue($value)
                    ) {
                        $unchangedCount++;

                        continue;
                    }

                    if ($this->valuesAreEqual($oldValue, $value)) {
                        $unchangedCount++;

                        continue;
                    }

                    $isSensitive = $this->isSensitiveKey($key)
                        || in_array(
                            $group,
                            self::SENSITIVE_GROUPS,
                            true,
                        );

                    PlatformSetting::query()->updateOrCreate(
                        [
                            'group' => $group,
                            'key' => $key,
                        ],
                        [
                            'value' => [
                                'value' => $value,
                            ],

                            'updated_by' => $user?->getKey(),

                            'is_sensitive' => $isSensitive,
                        ],
                    );

                    PlatformSettingAudit::query()->create([
                        'user_id' => $user?->getKey(),

                        'group' => $group,

                        'key' => $key,

                        'old_value' => [
                            'value' => $oldValue,
                        ],

                        'new_value' => [
                            'value' => $value,
                        ],

                        'status' => 'published',

                        'action' => $existing
                            ? 'updated'
                            : 'created',

                        'reason' => $reason !== ''
                            ? $reason
                            : null,

                        'ip_address' => $request->ip(),

                        'user_agent' => mb_substr(
                            (string) $request->userAgent(),
                            0,
                            1000,
                        ),

                        'approved_at' => now(),

                        'published_at' => now(),
                    ]);

                    $changedCount++;
                }
            }
        });

        $responseSettings = $this->currentSettings();

        if (! $isOwner && ! $isGeneralManagement) {
            foreach (self::EXECUTIVE_GROUPS as $group) {
                unset($responseSettings[$group]);
            }
        }

        return response()->json([
            'data' => $this->maskSensitiveSettings(
                $responseSettings,
            ),

            'message' => $changedCount > 0
                ? 'تم حفظ إعدادات المنصة وربطها بقاعدة البيانات بنجاح.'
                : 'لم توجد تغييرات جديدة للحفظ.',

            'meta' => [
                'changedCount' => $changedCount,

                'unchangedCount' => $unchangedCount,

                'savedBy' => $user
                    ? [
                        'id' => $user->getKey(),

                        'name' => $user->getAttribute('name')
                            ?? $user->getAttribute('name_ar')
                            ?? $user->getAttribute('nameAr'),

                        'email' => $user->getAttribute('email'),
                    ]
                    : null,

                'savedAt' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * عرض سجل تغييرات الإعدادات.
     */
    public function audits(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user && $this->canViewSettings($user),
            403,
            'ليس لديك صلاحية لعرض سجل الإعدادات.',
        );

        $query = PlatformSettingAudit::query()
            ->with('user:id,name,email')
            ->latest('id');

        if ($request->filled('group')) {
            $query->where(
                'group',
                (string) $request->query('group'),
            );
        }

        if ($request->filled('key')) {
            $query->where(
                'key',
                (string) $request->query('key'),
            );
        }

        if ($request->filled('status')) {
            $query->where(
                'status',
                (string) $request->query('status'),
            );
        }

        $perPage = min(
            max((int) $request->query('per_page', 25), 1),
            100,
        );

        $audits = $query->paginate($perPage);

        $audits->getCollection()->transform(
            function (PlatformSettingAudit $audit): array {
                $sensitive = $this->isSensitiveKey(
                    (string) $audit->key,
                );

                return [
                    'id' => $audit->id,

                    'group' => $audit->group,

                    'key' => $audit->key,

                    'oldValue' => $sensitive
                        ? $this->maskAuditValue($audit->old_value)
                        : $audit->old_value,

                    'newValue' => $sensitive
                        ? $this->maskAuditValue($audit->new_value)
                        : $audit->new_value,

                    'status' => $audit->status,

                    'action' => $audit->action,

                    'reason' => $audit->reason,

                    'ipAddress' => $audit->ip_address,

                    'user' => $audit->user
                        ? [
                            'id' => $audit->user->id,
                            'name' => $audit->user->name,
                            'email' => $audit->user->email,
                        ]
                        : null,

                    'approvedAt' => $audit->approved_at?->toISOString(),

                    'publishedAt' => $audit->published_at?->toISOString(),

                    'createdAt' => $audit->created_at?->toISOString(),
                ];
            },
        );

        return response()->json($audits);
    }

    /**
     * استعادة قيمة إعداد سابقة من سجل التدقيق.
     */
    public function rollback(
        Request $request,
        PlatformSettingAudit $audit,
    ): JsonResponse {
        $user = $request->user();
        $permissions = $this->getUserPermissions($user);

        abort_unless(
            $user && (
                $this->isPlatformOwner($user)
                || in_array(
                    'settings.rollback',
                    $permissions,
                    true,
                )
            ),
            403,
            'ليس لديك صلاحية استعادة إصدار سابق.',
        );

        $reason = trim(
            (string) $request->input(
                'reason',
                'استعادة إصدار سابق من إعدادات المنصة.',
            ),
        );

        $restoredValue = $audit->old_value['value'] ?? null;

        DB::transaction(function () use (
            $audit,
            $restoredValue,
            $user,
            $request,
            $reason,
        ): void {
            $current = PlatformSetting::query()
                ->where('group', $audit->group)
                ->where('key', $audit->key)
                ->lockForUpdate()
                ->first();

            $currentValue = $current?->value['value'] ?? null;

            PlatformSetting::query()->updateOrCreate(
                [
                    'group' => $audit->group,
                    'key' => $audit->key,
                ],
                [
                    'value' => [
                        'value' => $restoredValue,
                    ],

                    'is_sensitive' => $this->isSensitiveKey(
                        (string) $audit->key,
                    ),

                    'updated_by' => $user->getKey(),
                ],
            );

            PlatformSettingAudit::query()->create([
                'user_id' => $user->getKey(),

                'group' => $audit->group,

                'key' => $audit->key,

                'old_value' => [
                    'value' => $currentValue,
                ],

                'new_value' => [
                    'value' => $restoredValue,
                ],

                'status' => 'published',

                'action' => 'rolled_back',

                'reason' => $reason,

                'ip_address' => $request->ip(),

                'user_agent' => mb_substr(
                    (string) $request->userAgent(),
                    0,
                    1000,
                ),

                'approved_at' => now(),

                'published_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'تمت استعادة القيمة السابقة بنجاح.',

            'data' => $this->maskSensitiveSettings(
                $this->currentSettings(),
            ),
        ]);
    }

    /**
     * قراءة الإعدادات الافتراضية والمحفوظة.
     */
    private function currentSettings(): array
    {
        $defaults = config('zad-settings.defaults', []);

        if (! is_array($defaults)) {
            $defaults = [];
        }

        $stored = PlatformSetting::query()
            ->get()
            ->groupBy('group')
            ->map(
                fn (Collection $items): array => $items
                    ->mapWithKeys(
                        fn (PlatformSetting $item): array => [
                            $item->key => $item->value['value'] ?? null,
                        ],
                    )
                    ->all(),
            )
            ->all();

        return array_replace_recursive(
            $defaults,
            $stored,
        );
    }

    /**
     * جلب صلاحيات المستخدم من أكثر من نظام محتمل.
     */
    private function getUserPermissions(?object $user): array
    {
        if (! $user) {
            return [];
        }

        $permissions = [];

        if (method_exists($user, 'getAllPermissions')) {
            try {
                $permissions = $user
                    ->getAllPermissions()
                    ->pluck('name')
                    ->filter()
                    ->values()
                    ->all();
            } catch (Throwable) {
                $permissions = [];
            }
        }

        $attributePermissions = $user->getAttribute('permissions');

        if (is_array($attributePermissions)) {
            foreach ($attributePermissions as $permission) {
                if (is_string($permission)) {
                    $permissions[] = $permission;
                } elseif (
                    is_array($permission)
                    && isset($permission['name'])
                ) {
                    $permissions[] = (string) $permission['name'];
                }
            }
        }

        if (method_exists($user, 'permissions')) {
            try {
                $relationPermissions = $user
                    ->permissions()
                    ->pluck('name')
                    ->filter()
                    ->values()
                    ->all();

                $permissions = array_merge(
                    $permissions,
                    $relationPermissions,
                );
            } catch (Throwable) {
                // لا نوقف الطلب إذا لم تكن العلاقة موجودة أو مهيأة.
            }
        }

        return array_values(
            array_unique(
                array_filter(
                    $permissions,
                    fn ($permission): bool => is_string($permission)
                        && trim($permission) !== '',
                ),
            ),
        );
    }

    private function canViewSettings(object $user): bool
    {
        if ($this->isPlatformOwner($user)) {
            return true;
        }

        $permissions = $this->getUserPermissions($user);

        return $this->hasAnyPermission(
            $permissions,
            self::VIEW_PERMISSIONS,
        );
    }

    private function canManageSettings(
        object $user,
        array $permissions,
    ): bool {
        if ($this->isPlatformOwner($user)) {
            return true;
        }

        return $this->hasAnyPermission(
            $permissions,
            self::MANAGE_PERMISSIONS,
        );
    }

    private function isPlatformOwner(?object $user): bool
    {
        if (! $user) {
            return false;
        }

        if (
            (bool) $user->getAttribute('is_platform_owner') === true
            || (bool) $user->getAttribute('isPlatformOwner') === true
            || (bool) $user->getAttribute('is_owner') === true
            || $user->getAttribute('role') === 'platform_owner'
        ) {
            return true;
        }

        if (
            method_exists($user, 'hasRole')
            && $user->hasRole('platform_owner')
        ) {
            return true;
        }

        if (method_exists($user, 'roles')) {
            try {
                return $user
                    ->roles()
                    ->where('key', 'platform_owner')
                    ->exists();
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }

    private function isGeneralManagementUser(
        object $user,
        array $permissions,
    ): bool {
        if ($this->isPlatformOwner($user)) {
            return true;
        }

        $role = (string) (
            $user->getAttribute('role')
            ?? ''
        );

        if (in_array($role, [
            'general_manager',
            'general_management',
            'executive_manager',
        ], true)) {
            return true;
        }

        if (
            method_exists($user, 'hasRole')
            && (
                $user->hasRole('general_manager')
                || $user->hasRole('general_management')
            )
        ) {
            return true;
        }

        return $this->hasAnyPermission(
            $permissions,
            [
                'settings.executive.view',
                'settings.executive.manage',
                'general_management.settings.view',
                'general_management.settings.manage',
                'general_management.settings.approve',
            ],
        );
    }

    private function hasAnyPermission(
        array $userPermissions,
        array $requiredPermissions,
    ): bool {
        if (in_array('*', $userPermissions, true)) {
            return true;
        }

        foreach ($requiredPermissions as $permission) {
            if (in_array($permission, $userPermissions, true)) {
                return true;
            }
        }

        return false;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = Str::lower($key);

        foreach (self::SENSITIVE_KEYWORDS as $keyword) {
            if (str_contains($normalizedKey, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function maskSensitiveSettings(array $settings): array
    {
        foreach ($settings as $group => $values) {
            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $key => $value) {
                if (
                    $this->isSensitiveKey((string) $key)
                    && $value !== null
                    && $value !== ''
                ) {
                    $settings[$group][$key] = '••••••••••••';
                }
            }
        }

        return $settings;
    }

    private function maskAuditValue(
        ?array $value,
    ): ?array {
        if ($value === null) {
            return null;
        }

        return [
            'value' => isset($value['value'])
                && $value['value'] !== null
                && $value['value'] !== ''
                    ? '••••••••••••'
                    : null,
        ];
    }

    private function isMaskedValue(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $normalized = trim($value);

        return $normalized !== ''
            && preg_match('/^[•●*]+$/u', $normalized) === 1;
    }

    private function valuesAreEqual(
        mixed $first,
        mixed $second,
    ): bool {
        return json_encode(
            $first,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) === json_encode(
            $second,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function sanitizeIdentifier(
        string $value,
        int $maximumLength,
    ): string {
        $value = trim($value);

        $value = preg_replace(
            '/[^A-Za-z0-9_.\-]/',
            '',
            $value,
        ) ?? '';

        return mb_substr(
            $value,
            0,
            $maximumLength,
        );
    }
}