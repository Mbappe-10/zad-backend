<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\PlatformAuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminRolePermissionController extends Controller
{
    private function authorizeActor(Request $request, bool $write): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 401);
        abort_unless($actor->isActive() && $actor->isApproved(), 403);

        $keys = $write
            ? ['permissions.update', 'permissions.edit', 'permissions.manage']
            : ['permissions.view', 'permissions.manage'];

        abort_unless(
            $actor->isPlatformOwner() || $actor->hasAnyPermission($keys),
            403,
            'Permission denied.'
        );

        return $actor;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeActor($request, false);

        $roles = Role::query()
            ->with('permissions:id,key')
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'key' => $role->key,
                'name_ar' => $role->name_ar,
                'name_en' => $role->name_en,
                'is_system' => $role->is_system,
                'is_active' => $role->is_active,
                'permission_ids' => $role->permissions->pluck('id')->values(),
                'permission_keys' => $role->permissions->pluck('key')->values(),
            ]);

        $permissions = Permission::query()
            ->orderBy('module')
            ->orderBy('action')
            ->get([
                'id', 'key', 'module', 'action',
                'name_ar', 'name_en',
                'is_active', 'is_sensitive',
                'requires_approval'
            ]);

        return response()->json([
            'data' => [
                'roles' => $roles,
                'permissions' => $permissions,
            ],
        ]);
    }

    public function update(Request $request, int $role): JsonResponse
    {
        $actor = $this->authorizeActor($request, true);

        $protectedRole = Role::query()->findOrFail($role);
        abort_if($protectedRole->is_system, 403, 'System role is protected.');

        $data = $request->validate([
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $requestedIds = collect($data['permission_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return DB::transaction(function () use (
            $request, $actor, $role, $requestedIds
        ): JsonResponse {
            $entity = Role::query()
                ->lockForUpdate()
                ->findOrFail($role);

            abort_if($entity->is_system, 403, 'System role is protected.');
            abort_unless($entity->is_active, 403, 'Inactive role cannot be modified.');

            $current = $entity->permissions()
                ->get(['permissions.id', 'permissions.key', 'permissions.is_active']);

            $requested = Permission::query()
                ->whereIn('id', $requestedIds)
                ->get(['id', 'key', 'is_active']);

            if ($requested->count() !== count($requestedIds)) {
                throw ValidationException::withMessages([
                    'permission_ids' => 'Unknown permission.',
                ]);
            }

            $existingInactiveIds = $current
                ->filter(fn ($p) => !$p->is_active)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $newInactive = $requested
                ->filter(fn ($p) => !$p->is_active)
                ->pluck('id')
                ->diff($existingInactiveIds);

            if ($newInactive->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'permission_ids' => 'Cannot assign a new inactive permission.',
                ]);
            }

            $missingInactive = collect($existingInactiveIds)
                ->diff($requestedIds);

            if ($missingInactive->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'permission_ids' => 'Existing inactive permissions must be preserved.',
                ]);
            }

            if (!$actor->isPlatformOwner()) {
                $allKeys = $current
                    ->filter(fn ($permission) => $permission->is_active)
                    ->pluck('key')
                    ->merge(
                        $requested
                            ->filter(fn ($permission) => $permission->is_active)
                            ->pluck('key')
                    )
                    ->unique();

                foreach ($allKeys as $key) {
                    abort_unless(
                        $actor->hasPermission($key),
                        403,
                        'Permission grant ceiling exceeded.'
                    );
                }
            }

            $before = $current->pluck('key')->sort()->values()->all();
            $after = $requested->pluck('key')->sort()->values()->all();

            if ($before !== $after) {
                $entity->permissions()->sync($requestedIds);

                $entity->users()
                    ->where('is_platform_owner', false)
                    ->each(fn (User $user) => $user->tokens()->delete());

                PlatformAuditLog::create([
                    'user_id' => $actor->id,
                    'resource' => 'role_permissions',
                    'record_id' => $entity->id,
                    'action' => 'updated',
                    'before' => ['permission_keys' => $before],
                    'after' => ['permission_keys' => $after],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            return response()->json([
                'message' => 'Role permissions saved.',
                'data' => [
                    'role_id' => $entity->id,
                    'permission_ids' => $requestedIds,
                    'permission_keys' => $after,
                ],
            ]);
        });
    }
}