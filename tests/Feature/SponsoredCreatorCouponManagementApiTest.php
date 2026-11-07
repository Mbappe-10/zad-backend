<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SponsoredCreatorCouponManagementApiTest extends TestCase
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

    private function authenticate(bool $owner): void
    {
        $user = User::factory()->create([
            'is_platform_owner' => $owner,
        ]);

        Sanctum::actingAs($user);
    }

    public function test_guest_cannot_list_coupons(): void
    {
        $this->getJson(
            '/api/admin/sponsored-creator-coupons'
        )->assertUnauthorized();
    }

    public function test_non_owner_cannot_list_or_manage_coupons(): void
    {
        $this->authenticate(false);

        $base = '/api/admin/sponsored-creator-coupons';

        $this->getJson($base)->assertForbidden();

        $this->postJson("$base/1/pause")
            ->assertForbidden();

        $this->postJson("$base/1/resume")
            ->assertForbidden();

        $this->postJson("$base/1/extend", [
            'minutes' => 20,
        ])->assertForbidden();
    }

    public function test_owner_can_list_coupons(): void
    {
        $this->authenticate(true);

        $this->getJson(
            '/api/admin/sponsored-creator-coupons'
        )
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'current_page',
                    'data',
                    'per_page',
                    'total',
                ],
            ])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_owner_cannot_submit_invalid_extension(): void
    {
        $this->authenticate(true);

        $this->postJson(
            '/api/admin/sponsored-creator-coupons/1/extend',
            ['minutes' => 999]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['minutes']);
    }

    public function test_owner_gets_not_found_for_missing_coupon(): void
    {
        $this->authenticate(true);

        $base = '/api/admin/sponsored-creator-coupons/999999';

        $this->postJson("$base/pause")
            ->assertNotFound();

        $this->postJson("$base/resume")
            ->assertNotFound();

        $this->postJson("$base/extend", [
            'minutes' => 10,
        ])->assertNotFound();
    }
    public function test_owner_receives_management_fields_when_creating_coupon(): void
    {
        $this->authenticate(true);

        $tag = bin2hex(random_bytes(6));
        $now = now();

        $familyId = DB::table('productive_families')->insertGetId([
            'code' => 'SCAPI-F-' . $tag,
            'owner_name' => 'API Test Family',
            'phone' => '05' . random_int(10000000, 99999999),
            'status' => 'approved',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $storeId = DB::table('stores')->insertGetId([
            'productive_family_id' => $familyId,
            'name_ar' => 'متجر اختبار API',
            'slug' => 'scapi-store-' . $tag,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $creatorId = DB::table('launch_creators')->insertGetId([
            'code' => 'SCAPI-C-' . $tag,
            'name' => 'API Test Creator',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $productId = DB::table('products')->insertGetId([
            'store_id' => $storeId,
            'name_ar' => 'منتج اختبار API',
            'price' => 20,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->postJson(
            '/api/admin/sponsored-creator-coupons',
            [
                'creator_id' => $creatorId,
                'store_id' => $storeId,
                'allowed_products' => [
                    [
                        'product_id' => $productId,
                        'max_quantity' => 2,
                    ],
                ],
                'gross_cap' => 60,
                'display_name' => 'تجربة بلوغر مكة',
                'code_label' => 'zad620',
            ]
        );

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'coupon.display_name',
                'تجربة بلوغر مكة'
            )
            ->assertJsonPath('coupon.code_label', 'ZAD620');

        $code = $response->json('one_time_plain_code');

        $this->assertIsString($code);
        $this->assertStringStartsWith('ZSC-', $code);

        $this->assertDatabaseHas('sponsored_creator_coupons', [
            'id' => $response->json('coupon.id'),
            'display_name' => 'تجربة بلوغر مكة',
            'code_label' => 'ZAD620',
            'code_hash' => hash('sha256', $code),
            'status' => 'draft',
        ]);
    }
}