<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionInheritanceTest extends TestCase
{
    use RefreshDatabase;

    private function setupIdentity(): array
    {
        $user = User::factory()->create([
            'is_platform_owner' => false,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $role = Role::create([
            'key' => 'inheritance_test_role',
            'name_ar' => 'Test Role',
            'name_en' => 'Test Role',
            'is_system' => false,
            'is_active' => true,
            'priority' => 0,
        ]);

        $permission = Permission::create([
            'key' => 'rbac.inheritance.view',
            'module' => 'rbac',
            'action' => 'view',
            'name_ar' => 'Test Permission',
            'name_en' => 'Test Permission',
            'is_active' => true,
        ]);

        $user->roles()->attach($role->id, [
            'assigned_by' => $user->id,
            'expires_at' => null,
        ]);

        return [$user, $role, $permission];
    }

    public function test_user_inherits_role_permission(): void
    {
        [$user, $role, $permission] = $this->setupIdentity();

        $role->permissions()->attach($permission->id);

        $this->assertTrue(
            $user->fresh()->hasPermission($permission->key)
        );
    }

    public function test_user_cannot_access_unassigned_permission(): void
    {
        [$user, $role, $permission] = $this->setupIdentity();

        $this->assertFalse(
            $user->fresh()->hasPermission($permission->key)
        );
    }

    public function test_direct_deny_overrides_role_permission(): void
    {
        [$user, $role, $permission] = $this->setupIdentity();

        $role->permissions()->attach($permission->id);

        $user->directPermissions()->attach($permission->id, [
            'effect' => 'deny',
            'granted_by' => $user->id,
            'expires_at' => null,
        ]);

        $this->assertFalse(
            $user->fresh()->hasPermission($permission->key)
        );
    }

    public function test_direct_allow_grants_permission_without_role_grant(): void
    {
        [$user, $role, $permission] = $this->setupIdentity();

        $user->directPermissions()->attach($permission->id, [
            'effect' => 'allow',
            'granted_by' => $user->id,
            'expires_at' => null,
        ]);

        $this->assertTrue(
            $user->fresh()->hasPermission($permission->key)
        );
    }

    public function test_revoking_role_permission_removes_inherited_access(): void
    {
        [$user, $role, $permission] = $this->setupIdentity();

        $role->permissions()->attach($permission->id);

        $this->assertTrue(
            $user->fresh()->hasPermission($permission->key)
        );

        $role->permissions()->detach($permission->id);

        $this->assertFalse(
            $user->fresh()->hasPermission($permission->key)
        );
    }

    public function test_expired_direct_allow_does_not_grant_access(): void
    {
        [$user, $role, $permission] = $this->setupIdentity();

        $user->directPermissions()->attach($permission->id, [
            'effect' => 'allow',
            'granted_by' => $user->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse(
            $user->fresh()->hasPermission($permission->key)
        );
    }
}