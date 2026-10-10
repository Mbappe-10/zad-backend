<?php

namespace Tests\Feature\Api;

use App\Models\PlatformRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketingHubTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_platform_owner' => true]));
    }

    private function creator(string $tag): int
    {
        return (int) DB::table('launch_creators')->insertGetId([
            'name' => $tag, 'code' => $tag, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function coupon(string $code): PlatformRecord
    {
        return PlatformRecord::query()->create([
            'resource' => 'coupons-offers', 'external_key' => $code,
            'status' => 'active',
            'payload' => ['kind' => 'coupon', 'code' => $code, 'nameAr' => 'اختبار '.$code,
                'value' => 5, 'discountType' => 'fixed', 'status' => 'active'],
        ]);
    }

    public function test_partner_directory_requires_authorization(): void
    {
        $this->getJson('/api/admin/launch-analytics/marketing-hub/partners')->assertUnauthorized();
    }

    public function test_partner_without_campaign_can_be_listed_and_link_coupon_with_signature_date(): void
    {
        $id = $this->creator('INDEPENDENT1');
        $coupon = $this->coupon('ZADOLD5');
        $this->owner();
        $this->getJson('/api/admin/launch-analytics/marketing-hub/partners')
            ->assertOk()->assertJsonFragment(['name' => 'INDEPENDENT1']);
        $response = $this->postJson('/api/admin/launch-analytics/marketing-hub/links', [
            'creator_id' => $id, 'coupon_code' => 'zadold5',
            'linked_at' => now()->subDay()->toIso8601String(),
            'paper_signed' => true, 'paper_signed_on' => now()->subDays(2)->toDateString(),
        ])->assertCreated();
        $linkId = (int) $response->json('data.id');
        $this->assertSame('active', $coupon->fresh()->status);
        $this->assertSame(5, $coupon->fresh()->payload['value']);
        $this->getJson('/api/admin/launch-analytics/marketing-hub/links?creator_id='.$id)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.paper_signed_on', now()->subDays(2)->toDateString())
            ->assertJsonPath('data.0.coupon_code', 'ZADOLD5');
        $this->patchJson('/api/admin/launch-analytics/marketing-hub/links/'.$linkId.'/close')->assertOk();
        $this->assertSame('active', $coupon->fresh()->status);
    }

    public function test_signed_contract_requires_manual_signature_date(): void
    {
        $id = $this->creator('INDEPENDENT2');
        $this->coupon('ZADOLD6');
        $this->owner();
        $this->postJson('/api/admin/launch-analytics/marketing-hub/links', [
            'creator_id' => $id, 'coupon_code' => 'ZADOLD6',
            'linked_at' => now()->subDay()->toIso8601String(), 'paper_signed' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('paper_signed_on');
    }

    public function test_old_coupon_cannot_be_linked_to_multiple_creators_at_once(): void
    {
        $first = $this->creator('INDEPENDENT3');
        $second = $this->creator('INDEPENDENT4');
        $this->coupon('ZADOLD7');
        $this->owner();
        $base = ['coupon_code' => 'ZADOLD7', 'paper_signed' => false,
            'linked_at' => now()->subDays(2)->toIso8601String()];
        $this->postJson('/api/admin/launch-analytics/marketing-hub/links', [
            ...$base, 'creator_id' => $first,
        ])->assertCreated();
        $this->postJson('/api/admin/launch-analytics/marketing-hub/links', [
            ...$base, 'creator_id' => $second,
        ])->assertUnprocessable();
    }

    public function test_coupon_report_is_authorized_and_returns_empty_real_counts(): void
    {
        $coupon = $this->coupon('EMPTYZAD');
        $this->owner();
        $this->getJson('/api/admin/launch-analytics/marketing-hub/coupon-reports/'.$coupon->id)
            ->assertOk()
            ->assertJsonPath('data.recorded_uses', 0)
            ->assertJsonPath('data.paid_order_count', 0)
            ->assertJsonPath('data.paid_order_gmv', 0);
    }
}
