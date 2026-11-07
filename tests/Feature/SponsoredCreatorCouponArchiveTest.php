<?php

namespace Tests\Feature;

use App\Models\SponsoredCreatorCoupon;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Services\SponsoredCreatorCouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SponsoredCreatorCouponArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = new User();
        $owner->id = 1;
        $owner->exists = true;
        $owner->is_platform_owner = true;

        return $owner;
    }

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

    private function coupon(string $status): SponsoredCreatorCoupon
    {
        $now = now();
        $tag = bin2hex(random_bytes(6));

        $owner = User::factory()->create();

        $familyId = DB::table('productive_families')->insertGetId([
            'code' => 'SCA-F-' . $tag,
            'owner_name' => 'Archive Test Family',
            'phone' => '05' . random_int(10000000, 99999999),
            'status' => 'approved',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $storeId = DB::table('stores')->insertGetId([
            'productive_family_id' => $familyId,
            'name_ar' => 'Archive Test Store',
            'slug' => 'sca-store-' . $tag,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $creatorId = DB::table('launch_creators')->insertGetId([
            'code' => 'SCA-C-' . $tag,
            'name' => 'Archive Test Creator',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $coupon = new SponsoredCreatorCoupon();
        $coupon->creator_id = $creatorId;
        $coupon->store_id = $storeId;
        $coupon->created_by = $owner->id;
        $coupon->code_hash = hash('sha256', bin2hex(random_bytes(32)));
        $coupon->allowed_products = [];
        $coupon->gross_cap = 100;
        $coupon->status = $status;
        $coupon->activated_at = $now->copy()->subMinute();
        $coupon->expires_at = $now->copy()->addMinutes(10);
        $coupon->save();

        return $coupon;
    }
    public function test_active_coupon_cannot_be_archived(): void
    {
        $coupon = $this->coupon('active');

        try {
            app(SponsoredCreatorCouponService::class)
                ->archive($this->owner(), $coupon->id);

            $this->fail('Expected HTTP 409.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertNull($coupon->fresh()->archived_at);
    }

    public function test_paused_coupon_cannot_be_archived(): void
    {
        $coupon = $this->coupon('paused');

        try {
            app(SponsoredCreatorCouponService::class)
                ->archive($this->owner(), $coupon->id);

            $this->fail('Expected HTTP 409.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertNull($coupon->fresh()->archived_at);
    }

    public function test_revoked_coupon_can_be_archived(): void
    {
        $coupon = $this->coupon('revoked');

        $result = app(SponsoredCreatorCouponService::class)
            ->archive($this->owner(), $coupon->id);

        $this->assertNotNull($result->archived_at);
        $this->assertSame('revoked', $coupon->fresh()->status);
        $this->assertNotNull($coupon->fresh()->archived_at);
    }

    public function test_archiving_twice_preserves_original_timestamp(): void
    {
        $coupon = $this->coupon('revoked');
        $service = app(SponsoredCreatorCouponService::class);

        $first = $service->archive($this->owner(), $coupon->id);
        $firstTimestamp = $first->archived_at->toISOString();

        $second = $service->archive($this->owner(), $coupon->id);

        $this->assertSame(
            $firstTimestamp,
            $second->archived_at->toISOString()
        );
    }
    public function test_archived_coupon_is_hidden_from_list_but_remains_in_database(): void
    {
        $visible = $this->coupon('revoked');
        $archived = $this->coupon('revoked');

        app(SponsoredCreatorCouponService::class)
            ->archive($this->owner(), $archived->id);

        $owner = User::factory()->create([
            'is_platform_owner' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson(
            '/api/admin/sponsored-creator-coupons'
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 1);

        $ids = collect($response->json('data.data'))
            ->pluck('id')
            ->all();

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($archived->id, $ids);

        $this->assertDatabaseHas('sponsored_creator_coupons', [
            'id' => $archived->id,
            'status' => 'revoked',
        ]);

        $this->assertNotNull(
            $archived->fresh()->archived_at
        );
    }
}