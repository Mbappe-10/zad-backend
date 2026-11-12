<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserPermissionDetailsController extends Controller
{
    public function __invoke(Request $request, int $record): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless($actor->isActive() && $actor->isApproved(), 403);
        if (!$actor->isPlatformOwner()) {
            abort_unless($actor->hasPermission('users.view'), 403);
            abort_unless($actor->hasAnyPermission(['permissions.view', 'permissions.manage']), 403);
        }

        $entry = PlatformRecord::query()->where('resource', 'users')->findOrFail($record);
        $user = app(AdminIdentityService::class)->entity($entry);
        abort_unless($user instanceof User && !$user->trashed(), 404);
        $user->load(['roles.permissions', 'directPermissions']);

        $inherited = $user->roles
            ->filter(function (Role $role) {
                if (!$role->is_active) return false;
                $expires = $role->pivot?->expires_at;
                return !$expires || !now()->greaterThan($expires);
            })
            ->flatMap(fn (Role $role) => $role->permissions)
            ->filter(fn (Permission $p) => $p->is_active)
            ->pluck('key')->unique()->sort()->values()->all();

        $direct = $user->directPermissions->map(function (Permission $p) {
            $expires = $p->pivot?->expires_at;
            return [
                'key' => $p->key,
                'effect' => $p->pivot?->effect,
                'is_active' => (bool) $p->is_active,
                'is_expired' => (bool) ($expires && now()->greaterThan($expires)),
                'expires_at' => $expires,
            ];
        })->values()->all();

        return response()->json(['data' => [
            'inherited' => $inherited,
            'direct' => $direct,
            'effective' => $user->effectivePermissions(),
        ]])->header('Cache-Control', 'no-store');
    }
}