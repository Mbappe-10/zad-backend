<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SponsoredCreatorCouponArchiveApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            !app()->environment('testing')
            || DB::getDefaultConnection() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:'
        ) {
            throw new \RuntimeException(
                'STOP: SQLite in-memory testing required.'
            );
        }
    }

    public function test_guest_cannot_archive_coupon(): void
    {
        $this->postJson(
            '/api/admin/sponsored-creator-coupons/1/archive'
        )->assertUnauthorized();
    }

    public function test_non_owner_cannot_archive_coupon(): void
    {
        $user = User::factory()->create([
            'is_platform_owner' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson(
            '/api/admin/sponsored-creator-coupons/1/archive'
        )->assertForbidden();
    }

    public function test_owner_gets_404_for_missing_coupon(): void
    {
        $user = User::factory()->create([
            'is_platform_owner' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson(
            '/api/admin/sponsored-creator-coupons/999999/archive'
        )->assertNotFound();
    }
}