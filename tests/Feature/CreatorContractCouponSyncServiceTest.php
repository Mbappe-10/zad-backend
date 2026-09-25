<?php

namespace Tests\Feature;

use App\Models\LaunchCampaignCreator;
use App\Models\PlatformRecord;
use App\Services\CreatorContractCouponSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreatorContractCouponSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private CreatorContractCouponSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(
            CreatorContractCouponSyncService::class
        );
    }

    public function test_active_contract_activates_coupon_and_syncs_dates(): void
    {
        $coupon = $this->coupon('CREATOR10');

        $assignment = $this->assignment([
            'coupon_code' => 'CREATOR10',
            'status' => 'active',
            'contract_status' => 'active',
            'contract_starts_at' => now()->subDay(),
            'contract_ends_at' => now()->addDays(10),
        ]);

        $this->service->sync($assignment);

        $coupon->refresh();

        $this->assertSame('active', $coupon->status);
        $this->assertSame(
            'active',
            $coupon->payload['status']
        );

        $this->assertSame(
            $assignment->contract_starts_at->toDateString(),
            $coupon->payload['startDate']
        );

        $this->assertSame(
            $assignment->contract_ends_at->toDateString(),
            $coupon->payload['endDate']
        );

        $this->assertTrue(
            $coupon->payload['creatorContractManaged']
        );

        $this->assertSame(
            $assignment->id,
            $coupon->payload['creatorAssignmentId']
        );

        $this->assertSame(
            (int) $assignment->contract_version,
            $coupon->payload['creatorContractVersion']
        );
    }

    public function test_suspended_contract_disables_coupon(): void
    {
        $coupon = $this->coupon('SUSPEND10');

        $assignment = $this->assignment([
            'coupon_code' => 'SUSPEND10',
            'status' => 'inactive',
            'contract_status' => 'suspended',
            'contract_starts_at' => now()->subDay(),
            'contract_ends_at' => now()->addDays(10),
        ]);

        $this->service->sync($assignment);

        $coupon->refresh();

        $this->assertSame('inactive', $coupon->status);
        $this->assertSame(
            'inactive',
            $coupon->payload['status']
        );
    }

    public function test_cancelled_contract_disables_coupon(): void
    {
        $coupon = $this->coupon('CANCEL10');

        $assignment = $this->assignment([
            'coupon_code' => 'CANCEL10',
            'status' => 'inactive',
            'contract_status' => 'cancelled',
            'contract_starts_at' => now()->subDay(),
            'contract_ends_at' => now()->addDays(10),
        ]);

        $this->service->sync($assignment);

        $coupon->refresh();

        $this->assertSame('inactive', $coupon->status);
        $this->assertSame(
            'inactive',
            $coupon->payload['status']
        );
    }

    public function test_expired_contract_disables_coupon(): void
    {
        $coupon = $this->coupon('EXPIRED10');

        $assignment = $this->assignment([
            'coupon_code' => 'EXPIRED10',
            'status' => 'active',
            'contract_status' => 'active',
            'contract_starts_at' => now()->subDays(10),
            'contract_ends_at' => now()->subDay(),
        ]);

        $this->service->sync($assignment);

        $coupon->refresh();

        $this->assertSame('inactive', $coupon->status);
        $this->assertSame(
            'inactive',
            $coupon->payload['status']
        );
    }

    public function test_future_contract_does_not_activate_coupon(): void
    {
        $coupon = $this->coupon('FUTURE10');

        $assignment = $this->assignment([
            'coupon_code' => 'FUTURE10',
            'status' => 'active',
            'contract_status' => 'active',
            'contract_starts_at' => now()->addDay(),
            'contract_ends_at' => now()->addDays(10),
        ]);

        $this->service->sync($assignment);

        $coupon->refresh();

        $this->assertSame('inactive', $coupon->status);
        $this->assertSame(
            'inactive',
            $coupon->payload['status']
        );
    }

    public function test_changing_coupon_disables_old_and_activates_new(): void
    {
        $old = $this->coupon('OLD10');
        $new = $this->coupon('NEW10');

        $assignment = $this->assignment([
            'coupon_code' => 'NEW10',
            'status' => 'active',
            'contract_status' => 'active',
            'contract_starts_at' => now()->subDay(),
            'contract_ends_at' => now()->addDays(10),
        ]);

        $this->service->sync(
            $assignment,
            'OLD10'
        );

        $old->refresh();
        $new->refresh();

        $this->assertSame('inactive', $old->status);
        $this->assertSame(
            'inactive',
            $old->payload['status']
        );

        $this->assertTrue(
            $old->payload['creatorContractManaged']
        );
        $this->assertSame(
            $assignment->id,
            $old->payload['creatorAssignmentId']
        );
        $this->assertSame(
            (int) $assignment->contract_version,
            $old->payload['creatorContractVersion']
        );

        $this->assertSame('active', $new->status);
        $this->assertSame(
            'active',
            $new->payload['status']
        );
    }

    public function test_unlinking_coupon_disables_previous_coupon(): void
    {
        $old = $this->coupon('UNLINK10');

        $assignment = $this->assignment([
            'coupon_code' => null,
            'status' => 'active',
            'contract_status' => 'active',
            'contract_starts_at' => now()->subDay(),
            'contract_ends_at' => now()->addDays(10),
        ]);

        $this->service->sync(
            $assignment,
            'UNLINK10'
        );

        $old->refresh();

        $this->assertSame('inactive', $old->status);
        $this->assertSame(
            'inactive',
            $old->payload['status']
        );

        $this->assertTrue(
            $old->payload['creatorContractManaged']
        );
        $this->assertSame(
            $assignment->id,
            $old->payload['creatorAssignmentId']
        );
        $this->assertSame(
            (int) $assignment->contract_version,
            $old->payload['creatorContractVersion']
        );
    }

    public function test_unrelated_normal_zad_coupon_is_untouched(): void
    {
        $normal = $this->coupon('ZADNORMAL');

        $beforeStatus = $normal->status;
        $beforePayload = $normal->payload;

        $assignment = $this->assignment([
            'coupon_code' => null,
            'status' => 'inactive',
            'contract_status' => 'cancelled',
            'contract_starts_at' => now()->subDay(),
            'contract_ends_at' => now()->addDays(10),
        ]);

        $this->service->sync($assignment);

        $normal->refresh();

        $this->assertSame(
            $beforeStatus,
            $normal->status
        );

        $this->assertSame(
            $beforePayload,
            $normal->payload
        );
    }

    public function test_non_coupon_platform_record_is_not_mutated(): void
    {
        $record = PlatformRecord::query()->create([
            'resource' => 'coupons-offers',
            'external_key' => 'NOTCOUPON',
            'status' => 'active',
            'payload' => [
                'code' => 'NOTCOUPON',
                'kind' => 'offer',
                'status' => 'active',
            ],
        ]);

        $assignment = $this->assignment([
            'coupon_code' => 'NOTCOUPON',
            'status' => 'inactive',
            'contract_status' => 'cancelled',
            'contract_starts_at' => now()->subDay(),
            'contract_ends_at' => now()->addDay(),
        ]);

        $this->service->sync($assignment);

        $record->refresh();

        $this->assertSame('active', $record->status);
        $this->assertSame(
            'active',
            $record->payload['status']
        );
    }

    private function coupon(string $code): PlatformRecord
    {
        return PlatformRecord::query()->create([
            'resource' => 'coupons-offers',
            'external_key' => $code,
            'status' => 'active',
            'payload' => [
                'code' => $code,
                'kind' => 'coupon',
                'status' => 'active',
                'discountType' => 'percentage',
                'value' => 10,
            ],
        ]);
    }

    private function assignment(
        array $overrides = []
    ): LaunchCampaignCreator {
        /*
         * Create minimal campaign + creator rows directly so this
         * test stays independent from factories.
         */
        $campaignId = DB::table(
            'launch_campaigns'
        )->insertGetId([
            'name' => 'B2 Test Campaign',
            'code' => 'B2-'.uniqid(),
            'tracking_code' => 'B2-CAMPAIGN-'.uniqid(),
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $creatorId = DB::table(
            'launch_creators'
        )->insertGetId([
            'name' => 'B2 Test Creator',
            'code' => 'B2-CREATOR-'.uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $defaults = [
            'campaign_id' => $campaignId,
            'creator_id' => $creatorId,
            'tracking_code' => 'B2-'.uniqid(),
            'commission_type' => 'percentage',
            'commission_value' => 10,
            'status' => 'active',

            'contract_reference' =>
                'B2-CONTRACT-'.uniqid(),

            'contract_title' =>
                'B2 Contract',

            'contract_content' =>
                'B2 automated test contract',

            'contract_starts_at' =>
                now()->subDay(),

            'contract_ends_at' =>
                now()->addDays(10),

            'contract_status' =>
                'active',

            'contract_version' => 1,
            'contract_updated_at' => now(),
        ];

        return LaunchCampaignCreator::query()->create(
            array_merge($defaults, $overrides)
        );
    }
}


