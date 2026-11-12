<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRolePermissionSessionTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $owner = User::factory()->create([
            'is_platform_owner' => true,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $member = User::factory()->create([
            'is_platform_owner' => false,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $role = Role::create([
            'key' => 'session_test_role',
            'name_ar' => 'Session Test',
            'name_en' => 'Session Test',
            'is_system' => false,
            'is_active' => true,
            'priority' => 0,
        ]);

        $permission = Permission::create([
            'key' => 'rbac.session.view',
            'module' => 'rbac',
            'action' => 'view',
            'name_ar' => 'Session View',
            'name_en' => 'Session View',
            'is_active' => true,
        ]);

        $role->permissions()->attach($permission->id);

        $member->roles()->attach($role->id, [
            'assigned_by' => $owner->id,
            'expires_at' => null,
        ]);

        $member->createToken('rbac-session-test');

        return [$owner, $member, $role, $permission];
    }

    public function test_changing_role_permissions_revokes_member_tokens(): void
    {
        [$owner, $member, $role, $permission] = $this->fixtures();

        $this->assertSame(1, $member->tokens()->count());

        $this->actingAs($owner)
            ->putJson("/api/admin/role-permissions/{$role->id}", [
                'permission_ids' => [],
            ])->assertOk();

        $this->assertSame(0, $member->tokens()->count());

        $this->assertFalse(
            $member->fresh()->hasPermission($permission->key)
        );
    }

    public function test_no_op_update_preserves_member_tokens(): void
    {
        [$owner, $member, $role, $permission] = $this->fixtures();

        $this->actingAs($owner)
            ->putJson("/api/admin/role-permissions/{$role->id}", [
                'permission_ids' => [$permission->id],
            ])->assertOk();

        $this->assertSame(1, $member->tokens()->count());
    }

    public function test_unrelated_member_tokens_are_preserved(): void
    {
        [$owner, $member, $role, $permission] = $this->fixtures();

        $unrelated = User::factory()->create([
            'is_platform_owner' => false,
        ]);

        $unrelated->createToken('unrelated-session');

        $this->actingAs($owner)
            ->putJson("/api/admin/role-permissions/{$role->id}", [
                'permission_ids' => [],
            ])->assertOk();

        $this->assertSame(0, $member->tokens()->count());
        $this->assertSame(1, $unrelated->tokens()->count());
    }
}