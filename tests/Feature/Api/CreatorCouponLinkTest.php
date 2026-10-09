<?php

namespace Tests\Feature\Api;

use App\Models\PlatformRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreatorCouponLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_coupon_link_is_blocked(): void
    {
        $this->getJson('/api/admin/launch-analytics/coupon-options')->assertUnauthorized();
    }

    public function test_existing_coupon_can_be_linked_without_being_modified_and_paper_signature_is_recorded(): void
    {
        $coupon = PlatformRecord::query()->create([
            'resource' => 'coupons-offers',
            'external_key' => 'OLD2026',
            'status' => 'active',
            'payload' => [
                'code' => 'OLD2026', 'kind' => 'coupon',
                'status' => 'active', 'discountType' => 'fixed', 'value' => 5,
            ],
        ]);
        $assignment = $this->assignment();
        Sanctum::actingAs(User::factory()->create(['is_platform_owner' => true]));

        $response = $this->postJson('/api/admin/launch-analytics/creator-coupon-links', [
            'assignment_id' => $assignment,
            'coupon_code' => 'old2026',
            'linked_at' => now()->subDay()->toISOString(),
            'paper_signed' => false,
        ])->assertCreated();

        $link = (int) $response->json('data.id');
        $coupon->refresh();
        $this->assertSame('active', $coupon->status);
        $this->assertSame('active', $coupon->payload['status']);
        $this->assertArrayNotHasKey('creatorContractManaged', $coupon->payload);

        $this->getJson('/api/admin/launch-analytics/creator-coupon-links?assignment_id='.$assignment)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.coupon_code', 'OLD2026')
            ->assertJsonPath('data.0.paper_signed', false);

        $this->patchJson('/api/admin/launch-analytics/creator-coupon-links/'.$link.'/signature', [
            'paper_signed' => true,
        ])->assertOk();

        $this->getJson('/api/admin/launch-analytics/creator-coupon-links?assignment_id='.$assignment)
            ->assertJsonPath('data.0.paper_signed', true);

        $this->patchJson('/api/admin/launch-analytics/creator-coupon-links/'.$link.'/close')
            ->assertOk();
        $coupon->refresh();
        $this->assertSame('active', $coupon->status);
        $this->assertSame('active', $coupon->payload['status']);
    }

    public function test_one_coupon_cannot_be_attributed_to_two_active_creators(): void
    {
        PlatformRecord::query()->create([
            'resource' => 'coupons-offers', 'external_key' => 'ONEOWNER', 'status' => 'active',
            'payload' => ['code' => 'ONEOWNER', 'kind' => 'coupon', 'status' => 'active'],
        ]);
        $a = $this->assignment();
        $b = $this->assignment();
        Sanctum::actingAs(User::factory()->create(['is_platform_owner' => true]));
        $payload = ['coupon_code' => 'ONEOWNER', 'linked_at' => now()->subDay()->toISOString(), 'paper_signed' => true];
        $this->postJson('/api/admin/launch-analytics/creator-coupon-links', [
            ...$payload, 'assignment_id' => $a,
        ])->assertCreated();
        $this->postJson('/api/admin/launch-analytics/creator-coupon-links', [
            ...$payload, 'assignment_id' => $b,
        ])->assertUnprocessable();
    }

    private function assignment(): int
    {
        $uid = uniqid();
        $campaignId = DB::table('launch_campaigns')->insertGetId([
            'name' => 'Test '.$uid, 'code' => 'C'.$uid, 'tracking_code' => 'TC'.$uid,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $creatorId = DB::table('launch_creators')->insertGetId([
            'name' => 'Creator '.$uid, 'code' => 'L'.$uid, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return (int) DB::table('launch_campaign_creators')->insertGetId([
            'campaign_id' => $campaignId, 'creator_id' => $creatorId,
            'tracking_code' => 'A'.$uid,
            'commission_type' => 'percentage', 'commission_value' => 5,
            'status' => 'active', 'contract_status' => 'draft',
            'contract_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
