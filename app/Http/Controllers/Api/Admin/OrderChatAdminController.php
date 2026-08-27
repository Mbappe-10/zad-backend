<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderChatPurgeLog;
use App\Models\PlatformSetting;
use App\Models\PlatformSettingAudit;
use App\Services\OrderChatRetentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class OrderChatAdminController extends Controller
{
    public function __construct(
        private readonly OrderChatRetentionService $retention,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $this->ensureCanView($request);

        return response()->json([
            'data' => $this->retention->statistics(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureCanManage($request);

        $data = $request->validate([
            'retention_days' => [
                'required',
                'integer',
                'min:1',
                'max:365',
            ],
            'reason' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        $user = $request->user();
        $days = (int) $data['retention_days'];

        DB::transaction(function () use (
            $request,
            $user,
            $days,
            $data,
        ): void {
            $existing = PlatformSetting::query()
                ->where(
                    'group',
                    OrderChatRetentionService::SETTINGS_GROUP,
                )
                ->where(
                    'key',
                    OrderChatRetentionService::RETENTION_KEY,
                )
                ->lockForUpdate()
                ->first();

            $oldValue = $existing?->value;

            if (is_array($oldValue)) {
                $oldValue = $oldValue['value'] ?? null;
            }

            PlatformSetting::query()->updateOrCreate(
                [
                    'group' =>
                        OrderChatRetentionService::SETTINGS_GROUP,

                    'key' =>
                        OrderChatRetentionService::RETENTION_KEY,
                ],
                [
                    'value' => [
                        'value' => $days,
                    ],
                    'updated_by' => $user->id,
                    'is_sensitive' => false,
                ],
            );

            PlatformSettingAudit::query()->create([
                'user_id' => $user->id,

                'group' =>
                    OrderChatRetentionService::SETTINGS_GROUP,

                'key' =>
                    OrderChatRetentionService::RETENTION_KEY,

                'old_value' => [
                    'value' => $oldValue,
                ],

                'new_value' => [
                    'value' => $days,
                ],

                'status' => 'published',
                'action' => $existing ? 'updated' : 'created',

                'reason' => trim(
                    (string) ($data['reason'] ?? ''),
                ) ?: 'تحديث مدة الاحتفاظ برسائل الطلبات.',

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
            'message' => 'تم تحديث مدة الاحتفاظ بالمحادثات.',
            'data' => $this->retention->statistics(),
        ]);
    }

    public function purge(Request $request): JsonResponse
    {
        $this->ensureCanManage($request);

        $request->validate([
            'confirm' => [
                'required',
                'accepted',
            ],
        ]);

        $result = $this->retention->purge(
            mode: OrderChatPurgeLog::MODE_MANUAL,
            executedBy: $request->user()->id,
        );

        return response()->json([
            'message' => $result['deleted_messages'] > 0
                ? 'تم حذف الرسائل المستحقة بنجاح.'
                : 'لا توجد رسائل مستحقة للحذف حاليًا.',
            'data' => $result,
        ]);
    }

    private function ensureCanView(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user !== null && (
                $this->isOwner($user) ||
                $this->hasAnyPermission(
                    $this->permissions($user),
                    [
                        'settings.view',
                        'settings.manage',
                        'general_management.settings.view',
                        'general_management.settings.manage',
                        'master_settings.access',
                    ],
                )
            ),
            403,
            'ليس لديك صلاحية عرض إعدادات المحادثات.',
        );
    }

    private function ensureCanManage(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user !== null && (
                $this->isOwner($user) ||
                $this->hasAnyPermission(
                    $this->permissions($user),
                    [
                        'settings.manage',
                        'settings.executive.manage',
                        'general_management.settings.manage',
                        'master_settings.access',
                    ],
                )
            ),
            403,
            'ليس لديك صلاحية إدارة المحادثات.',
        );
    }

    private function isOwner(object $user): bool
    {
        if (
            (bool) $user->getAttribute('is_platform_owner') ||
            (bool) $user->getAttribute('isPlatformOwner') ||
            (bool) $user->getAttribute('is_owner') ||
            $user->getAttribute('role') === 'platform_owner'
        ) {
            return true;
        }

        if (
            method_exists($user, 'hasRole') &&
            $user->hasRole('platform_owner')
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

    private function permissions(object $user): array
    {
        $permissions = [];

        if (method_exists($user, 'getAllPermissions')) {
            try {
                $permissions = $user
                    ->getAllPermissions()
                    ->pluck('name')
                    ->all();
            } catch (Throwable) {
                $permissions = [];
            }
        }

        $attributePermissions = $user->getAttribute(
            'permissions',
        );

        if (is_array($attributePermissions)) {
            foreach ($attributePermissions as $permission) {
                if (is_string($permission)) {
                    $permissions[] = $permission;
                } elseif (
                    is_array($permission) &&
                    isset($permission['name'])
                ) {
                    $permissions[] = (string) $permission['name'];
                }
            }
        }

        if (method_exists($user, 'permissions')) {
            try {
                $permissions = array_merge(
                    $permissions,
                    $user
                        ->permissions()
                        ->pluck('name')
                        ->all(),
                );
            } catch (Throwable) {
                // لا نوقف الطلب إذا لم تكن العلاقة موجودة.
            }
        }

        return array_values(
            array_unique(
                array_filter(
                    $permissions,
                    fn ($permission): bool =>
                        is_string($permission) &&
                        trim($permission) !== '',
                ),
            ),
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
}