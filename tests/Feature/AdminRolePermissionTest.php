<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRolePermissionTest extends TestCase
{
    use RefreshDatabase;


    public function test_owner_cannot_modify_inactive_role(): void
    {
        $owner = User::factory()->create([
            'is_platform_owner' => true,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $role = Role::create([
            'key' => 'inactive_security_test_role',
            'name_ar' => 'دور غير نشط',
            'name_en' => 'Inactive Security Role',
            'is_system' => false,
            'is_active' => false,
            'priority' => 0,
        ]);

        $this->actingAs($owner)
            ->putJson("/api/admin/role-permissions/{$role->id}", [
                'permission_ids' => [],
            ])->assertForbidden();
    }
    public function test_nonowner_preserves_inactive_history_within_grant_ceiling(): void
    {
        $actor = User::factory()->create([
            'is_platform_owner' => false,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $manage = Permission::create([
            'key' => 'permissions.manage',
            'module' => 'permissions',
            'action' => 'manage',
            'name_ar' => 'إدارة الصلاحيات',
            'name_en' => 'Manage Permissions',
            'is_active' => true,
        ]);

        $active = Permission::create([
            'key' => 'rbac.allowed.view',
            'module' => 'rbac',
            'action' => 'view',
            'name_ar' => 'عرض مسموح',
            'name_en' => 'Allowed View',
            'is_active' => true,
        ]);

        $inactive = Permission::create([
            'key' => 'rbac.legacy.inactive',
            'module' => 'rbac',
            'action' => 'legacy',
            'name_ar' => 'صلاحية قديمة',
            'name_en' => 'Legacy Permission',
            'is_active' => false,
        ]);

        $actor->directPermissions()->attach($manage->id, [
            'effect' => 'allow',
        ]);

        $actor->directPermissions()->attach($active->id, [
            'effect' => 'allow',
        ]);

        $role = Role::create([
            'key' => 'nonowner_legacy_role',
            'name_ar' => 'دور تاريخي',
            'name_en' => 'Legacy Role',
            'is_system' => false,
            'is_active' => true,
            'priority' => 0,
        ]);

        $role->permissions()->attach($inactive->id);

        $this->actingAs($actor)
            ->putJson("/api/admin/role-permissions/{$role->id}", [
                'permission_ids' => [$inactive->id, $active->id],
            ])->assertOk();

        $this->assertEqualsCanonicalizing(
            [$inactive->id, $active->id],
            $role->fresh()->permissions()->pluck('permissions.id')->all()
        );
    }

    public function test_nonowner_cannot_grant_permission_above_ceiling(): void
    {
        $actor = User::factory()->create([
            'is_platform_owner' => false,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $manage = Permission::create([
            'key' => 'permissions.manage',
            'module' => 'permissions',
            'action' => 'manage',
            'name_ar' => 'إدارة الصلاحيات',
            'name_en' => 'Manage Permissions',
            'is_active' => true,
        ]);

        $restricted = Permission::create([
            'key' => 'rbac.restricted.delete',
            'module' => 'rbac',
            'action' => 'delete',
            'name_ar' => 'حذف محظور',
            'name_en' => 'Restricted Delete',
            'is_active' => true,
        ]);

        $actor->directPermissions()->attach($manage->id, [
            'effect' => 'allow',
        ]);

        $role = Role::create([
            'key' => 'nonowner_restricted_role',
            'name_ar' => 'دور مقيد',
            'name_en' => 'Restricted Role',
            'is_system' => false,
            'is_active' => true,
            'priority' => 0,
        ]);

        $this->actingAs($actor)
            ->putJson("/api/admin/role-permissions/{$role->id}", [
                'permission_ids' => [$restricted->id],
            ])->assertForbidden();

        $this->assertFalse(
            $role->fresh()->permissions()
                ->where('permissions.id', $restricted->id)
                ->exists()
        );
    }
    public function test_guest_cannot_read_or_update_role_permissions(): void
    {
        $this->getJson('/api/admin/role-permissions')
            ->assertUnauthorized();

        $this->putJson('/api/admin/role-permissions/1', [
            'permission_ids' => [],
        ])->assertUnauthorized();
    }

    public function test_unprivileged_user_cannot_manage_role_permissions(): void
    {
        $user = User::factory()->create([
            'is_platform_owner' => false,
        ]);

        $this->actingAs($user)
            ->getJson('/api/admin/role-permissions')
            ->assertForbidden();

        $this->actingAs($user)
            ->putJson('/api/admin/role-permissions/1', [
                'permission_ids' => [],
            ])->assertForbidden();
    }

    public function test_owner_cannot_modify_system_role(): void
    {
        $owner = User::factory()->create([
            'is_platform_owner' => true,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $role = Role::create([
            'key' => 'protected_test_role',
            'name_ar' => 'دور محمي',
            'name_en' => 'Protected Test Role',
            'is_system' => true,
            'is_active' => true,
            'priority' => 0,
        ]);

        $this->actingAs($owner)
            ->putJson("/api/admin/role-permissions/{$role->id}", [
                'permission_ids' => [],
            ])->assertForbidden();
    }

    public function test_owner_cannot_assign_unknown_permission(): void
    {
        $owner = User::factory()->create([
            'is_platform_owner' => true,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $role = Role::create([
            'key' => 'custom_test_role',
            'name_ar' => 'دور تجريبي',
            'name_en' => 'Custom Test Role',
            'is_system' => false,
            'is_active' => true,
            'priority' => 0,
        ]);

        $this->actingAs($owner)
            ->putJson("/api/admin/role-permissions/{$role->id}", [
                'permission_ids' => [999999],
            ])->assertUnprocessable();
    }

    public function test_updating_one_role_preserves_other_role_permissions(): void
    {
        $owner = User::factory()->create([
            'is_platform_owner' => true,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $first = Role::create([
            'key' => 'first_test_role',
            'name_ar' => 'الدور الأول',
            'name_en' => 'First Role',
            'is_system' => false,
            'is_active' => true,
            'priority' => 0,
        ]);

        $second = Role::create([
            'key' => 'second_test_role',
            'name_ar' => 'الدور الثاني',
            'name_en' => 'Second Role',
            'is_system' => false,
            'is_active' => true,
            'priority' => 0,
        ]);

        $permission = Permission::create([
            'key' => 'rbac.test.view',
            'module' => 'rbac',
            'action' => 'view',
            'name_ar' => 'عرض تجريبي',
            'name_en' => 'Test View',
            'is_active' => true,
        ]);

        $second->permissions()->sync([$permission->id]);

        $this->actingAs($owner)
            ->putJson("/api/admin/role-permissions/{$first->id}", [
                'permission_ids' => [$permission->id],
            ])->assertOk();

        $this->assertTrue(
            $second->fresh()->permissions()
                ->where('permissions.id', $permission->id)
                ->exists()
        );
    }

    public function test_existing_inactive_permission_is_preserved_when_adding_active_permission(): void
    {
        $owner = User::factory()->create([
            'is_platform_owner' => true,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $role = Role::create([
            'key' => 'inactive_preservation_role',
            'name_ar' => 'دور اختبار الاحتفاظ',
            'name_en' => 'Inactive Preservation Role',
            'is_system' => false,
            'is_active' => true,
            'priority' => 0,
        ]);

        $inactive = Permission::create([
            'key' => 'rbac.inactive.old',
            'module' => 'rbac',
            'action' => 'old',
            'name_ar' => 'صلاحية قديمة',
            'name_en' => 'Old Permission',
            'is_active' => false,
        ]);

        $active = Permission::create([
            'key' => 'rbac.active.new',
            'module' => 'rbac',
            'action' => 'new',
            'name_ar' => 'صلاحية جديدة',
            'name_en' => 'New Permission',
            'is_active' => true,
        ]);

        $role->permissions()->sync([$inactive->id]);

        $this->actingAs($owner)
            ->putJson("/api/admin/role-permissions/{$role->id}", [
                'permission_ids' => [$inactive->id, $active->id],
            ])->assertOk();

        $this->assertEqualsCanonicalizing(
            [$inactive->id, $active->id],
            $role->fresh()->permissions()->pluck('permissions.id')->all()
        );
    }

    public function test_new_inactive_permission_cannot_be_assigned(): void
    {
        $owner = User::factory()->create([
            'is_platform_owner' => true,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $role = Role::create([
            'key' => 'inactive_rejection_role',
            'name_ar' => 'دور اختبار المنع',
            'name_en' => 'Inactive Rejection Role',
            'is_system' => false,
            'is_active' => true,
            'priority' => 0,
        ]);

        $inactive = Permission::create([
            'key' => 'rbac.inactive.rejected',
            'module' => 'rbac',
            'action' => 'rejected',
            'name_ar' => 'صلاحية غير نشطة',
            'name_en' => 'Inactive Permission',
            'is_active' => false,
        ]);

        $this->actingAs($owner)
            ->putJson("/api/admin/role-permissions/{$role->id}", [
                'permission_ids' => [$inactive->id],
            ])->assertUnprocessable();

        $this->assertFalse(
            $role->fresh()->permissions()
                ->where('permissions.id', $inactive->id)
                ->exists()
        );
    }

    public function test_existing_inactive_permission_cannot_be_silently_removed(): void
    {
        $owner = User::factory()->create([
            'is_platform_owner' => true,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $role = Role::create([
            'key' => 'inactive_removal_guard_role',
            'name_ar' => 'دور حماية الحذف',
            'name_en' => 'Inactive Removal Guard Role',
            'is_system' => false,
            'is_active' => true,
            'priority' => 0,
        ]);

        $inactive = Permission::create([
            'key' => 'rbac.inactive.protected',
            'module' => 'rbac',
            'action' => 'protected',
            'name_ar' => 'صلاحية قديمة محمية',
            'name_en' => 'Protected Inactive Permission',
            'is_active' => false,
        ]);

        $role->permissions()->sync([$inactive->id]);

        $this->actingAs($owner)
            ->putJson("/api/admin/role-permissions/{$role->id}", [
                'permission_ids' => [],
            ])->assertUnprocessable();

        $this->assertTrue(
            $role->fresh()->permissions()
                ->where('permissions.id', $inactive->id)
                ->exists()
        );
    }
}