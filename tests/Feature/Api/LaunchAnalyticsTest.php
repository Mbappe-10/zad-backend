<?php

namespace Tests\Feature\Api;

use App\Models\LaunchAttributionSession;
use App\Models\LaunchCampaign;
use App\Models\LaunchCampaignFamily;
use App\Models\LaunchOrderAttribution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LaunchAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_launch_analytics_requires_authentication_and_permission(): void
    {
        $this->getJson('/api/admin/launch-analytics/summary')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/launch-analytics/summary')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['is_platform_owner' => true]));
        $this->getJson('/api/admin/launch-analytics/summary')->assertOk();
    }

    public function test_public_tracking_deduplicates_retried_events(): void
    {
        $campaign = $this->campaign();
        $payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'visit',
            'campaign' => $campaign->code,
            'source' => 'launch',
            'medium' => 'qr',
        ];

        $first = $this->postJson('/api/v1/app/launch/track', $payload)
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/v1/app/launch/track', [
            ...$payload,
            'visitor_token' => $first['visitor_token'],
            'session_token' => $first['session_token'],
        ])->assertOk()->assertJsonPath('data.recorded', false);

        $this->assertDatabaseCount('launch_events', 1);
        $this->assertDatabaseCount('launch_attribution_sessions', 1);
    }

    public function test_cancelled_orders_are_excluded_and_revenue_uses_real_financial_rows(): void
    {
        $campaign = $this->campaign();
        [$cityId, $familyId, $storeId] = $this->businessFixtures();
        $session = LaunchAttributionSession::query()->create([
            'id' => (string) Str::uuid(),
            'visitor_hash' => hash('sha256', 'visitor'),
            'session_hash' => hash('sha256', 'session'),
            'first_campaign_id' => $campaign->id,
            'last_campaign_id' => $campaign->id,
            'first_source' => 'launch',
            'last_source' => 'launch',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $paidOrderId = DB::table('orders')->insertGetId([
            'number' => 'LAUNCH-PAID',
            'store_id' => $storeId,
            'city_id' => $cityId,
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => 100,
            'total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cancelledOrderId = DB::table('orders')->insertGetId([
            'number' => 'LAUNCH-CANCELLED',
            'store_id' => $storeId,
            'city_id' => $cityId,
            'status' => 'cancelled',
            'payment_status' => 'paid',
            'subtotal' => 200,
            'total' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$paidOrderId, $cancelledOrderId] as $orderId) {
            LaunchOrderAttribution::query()->create([
                'order_id' => $orderId,
                'session_id' => $session->id,
                'family_id' => $familyId,
                'first_campaign_id' => $campaign->id,
                'last_campaign_id' => $campaign->id,
                'first_source' => 'launch',
                'last_source' => 'launch',
                'creator_commission_amount' => 3,
                'creator_commission_status' => 'pending',
                'attributed_at' => now(),
            ]);
        }

        $providerId = DB::table('payment_providers')->insertGetId([
            'code' => 'test-gateway',
            'name' => 'Test Gateway',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payments')->insert([
            'order_id' => $paidOrderId,
            'provider_id' => $providerId,
            'reference' => 'PAY-LAUNCH-1',
            'gross_amount' => 100,
            'provider_fee' => 2,
            'net_amount' => 98,
            'status' => 'paid',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_commissions')->insert([
            'order_id' => $paidOrderId,
            'beneficiary_type' => 'platform',
            'beneficiary_id' => null,
            'base_amount' => 100,
            'commission_amount' => 15,
            'status' => 'released',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->create(['is_platform_owner' => true]));
        $this->getJson('/api/admin/launch-analytics/summary?campaign_id='.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.orders', 1)
            ->assertJsonPath('data.first_orders', 1)
            ->assertJsonPath('data.gmv', 100)
            ->assertJsonPath('data.creator_commission', 3)
            ->assertJsonPath('data.zad_revenue', 10);
    }

    private function campaign(): LaunchCampaign
    {
        return LaunchCampaign::query()->create([
            'name' => 'ZAD Sync Makkah Launch 2026',
            'code' => 'makkah-launch',
            'tracking_code' => 'campaign-makkah',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
    }

    /** @return array{int, int, int} */
    private function businessFixtures(): array
    {
        $cityId = DB::table('cities')->insertGetId([
            'code' => 'makkah',
            'name_ar' => 'مكة',
            'name_en' => 'Makkah',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $familyId = DB::table('productive_families')->insertGetId([
            'code' => 'family-launch',
            'owner_name' => 'أسرة الاختبار',
            'phone' => '0500000001',
            'status' => 'active',
            'city_id' => $cityId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $storeId = DB::table('stores')->insertGetId([
            'productive_family_id' => $familyId,
            'city_id' => $cityId,
            'name_ar' => 'متجر الاختبار',
            'slug' => 'launch-test-store',
            'status' => 'active',
            'is_open' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        LaunchCampaignFamily::query()->create([
            'campaign_id' => LaunchCampaign::query()->value('id'),
            'family_id' => $familyId,
            'booth_code' => '07',
            'tracking_code' => 'family-booth-07',
            'status' => 'active',
        ]);

        return [$cityId, $familyId, $storeId];
    }
}
