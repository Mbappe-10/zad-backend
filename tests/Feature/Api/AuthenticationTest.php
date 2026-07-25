<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_api_rejects_guests(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_active_approved_user_can_login_and_read_profile(): void
    {
        $user = User::query()->create([
            'name' => 'Production Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('StrongPassword!123'),
            'status' => 'active',
            'is_approved' => true,
        ]);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'StrongPassword!123',
        ])->assertOk()->assertJsonPath('user.email', $user->email);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::query()->create([
            'name' => 'Inactive User',
            'email' => 'inactive@example.test',
            'password' => Hash::make('StrongPassword!123'),
            'status' => 'inactive',
            'is_approved' => true,
        ]);

        $this->postJson('/auth/login', [
            'email' => 'inactive@example.test',
            'password' => 'StrongPassword!123',
        ])->assertUnprocessable();
    }
}
