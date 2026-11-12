<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminUserPermissionDetailsGuestTest extends TestCase
{
    public function test_guest_cannot_read_user_permission_details(): void
    {
        $this->getJson('/api/admin/users/1/permission-details')
            ->assertUnauthorized();
    }
}
