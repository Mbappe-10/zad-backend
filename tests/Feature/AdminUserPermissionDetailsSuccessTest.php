<?php

namespace Tests\Feature;

use App\Models\PlatformRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminUserPermissionDetailsSuccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_read_linked_user_permissions(): void
    {
        $owner = User::factory()->create([
            'is_platform_owner' => true,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $target = User::factory()->create([
            'is_platform_owner' => false,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $record = PlatformRecord::create([
            'resource' => 'users',
            'external_key' => 'rbac-success-test',
            'status' => 'active',
            'payload' => ['name' => 'RBAC Test User'],
        ]);

        DB::table('admin_identity_links')->insert([
            'resource' => 'users',
            'record_id' => $record->id,
            'entity_id' => $target->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)
            ->getJson("/api/admin/users/{$record->id}/permission-details")
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['inherited', 'direct', 'effective'],
            ])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_direct_deny_overrides_inherited_permission(): void
    {
        $owner = \App\Models\User::factory()->create([
            'is_platform_owner' => true,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $target = \App\Models\User::factory()->create([
            'is_platform_owner' => false,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $permission = \App\Models\Permission::create([
            'key' => 'rbac.final.view',
            'module' => 'rbac',
            'action' => 'view',
            'name_ar' => 'Final View',
            'name_en' => 'Final View',
            'is_active' => true,
        ]);

        $role = \App\Models\Role::create([
            'key' => 'rbac_final_role',
            'name_ar' => 'Final Role',
            'name_en' => 'Final Role',
            'is_system' => false,
            'is_active' => true,
            'priority' => 0,
        ]);

        $role->permissions()->attach($permission->id);
        $target->roles()->attach($role->id);
        $target->directPermissions()->attach($permission->id, [
            'effect' => 'deny',
        ]);

        $record = \App\Models\PlatformRecord::create([
            'resource' => 'users',
            'external_key' => 'rbac-final-permissions',
            'status' => 'active',
            'payload' => ['name' => 'Final RBAC Test'],
        ]);

        \Illuminate\Support\Facades\DB::table('admin_identity_links')->insert([
            'resource' => 'users',
            'record_id' => $record->id,
            'entity_id' => $target->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/admin/users/{$record->id}/permission-details")
            ->assertOk();

        $response->assertJsonPath('data.inherited.0', 'rbac.final.view');
        $response->assertJsonPath('data.direct.0.key', 'rbac.final.view');
        $response->assertJsonPath('data.direct.0.effect', 'deny');

        $this->assertNotContains(
            'rbac.final.view',
            $response->json('data.effective')
        );
    }
}