<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserPermissionDetailsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unprivileged_user_is_forbidden(): void
    {
        $actor = User::factory()->create([
            'is_platform_owner' => false,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $this->actingAs($actor)
            ->getJson('/api/admin/users/999999/permission-details')
            ->assertForbidden();
    }

    public function test_owner_gets_not_found_for_missing_record(): void
    {
        $owner = User::factory()->create([
            'is_platform_owner' => true,
            'status' => 'active',
            'is_approved' => true,
        ]);

        $this->actingAs($owner)
            ->getJson('/api/admin/users/999999/permission-details')
            ->assertNotFound();
    }
}