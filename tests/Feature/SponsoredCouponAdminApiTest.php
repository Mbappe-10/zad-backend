<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SponsoredCouponAdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            DB::getDefaultConnection() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:'
        ) {
            throw new \RuntimeException(
                'STOP: SQLite in-memory test database required.'
            );
        }
    }

    private function authenticatedUser(bool $owner): User
    {
        $user = User::factory()->create([
            'is_platform_owner' => $owner,
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_cannot_create_sponsored_coupon(): void
    {
        $this->postJson(
            '/api/admin/sponsored-creator-coupons',
            []
        )->assertUnauthorized();
    }

    public function test_non_owner_cannot_create_sponsored_coupon(): void
    {
        $this->authenticatedUser(false);

        $this->postJson(
            '/api/admin/sponsored-creator-coupons',
            []
        )->assertForbidden();
    }

    public function test_non_owner_cannot_activate_or_revoke(): void
    {
        $this->authenticatedUser(false);

        $this->postJson(
            '/api/admin/sponsored-creator-coupons/1/activate',
            ['duration_minutes' => 15]
        )->assertForbidden();

        $this->postJson(
            '/api/admin/sponsored-creator-coupons/1/revoke',
            []
        )->assertForbidden();
    }

    public function test_owner_reaches_creation_validation(): void
    {
        $this->authenticatedUser(true);

        $this->postJson(
            '/api/admin/sponsored-creator-coupons',
            []
        )->assertUnprocessable();
    }
}