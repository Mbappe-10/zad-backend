<?php

namespace Tests\Feature;

use App\Models\LaunchCampaignCreator;
use App\Models\LaunchCreatorContractVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreatorContractVersionHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_contract_version_cannot_be_overwritten_by_duplicate_version(): void
    {
        $assignment = $this->assignment();

        $this->version($assignment, 1, 'Original Contract');

        $this->expectException(QueryException::class);

        $this->version($assignment, 1, 'Overwritten Contract');
    }

    public function test_new_version_preserves_previous_version(): void
    {
        $assignment = $this->assignment();

        $this->version($assignment, 1, 'Version One');
        $this->version($assignment, 2, 'Version Two');

        $this->assertDatabaseHas('launch_creator_contract_versions', [
            'launch_campaign_creator_id' => $assignment->id,
            'version' => 1,
            'contract_title' => 'Version One',
        ]);

        $this->assertDatabaseHas('launch_creator_contract_versions', [
            'launch_campaign_creator_id' => $assignment->id,
            'version' => 2,
            'contract_title' => 'Version Two',
        ]);

        $this->assertSame(
            2,
            LaunchCreatorContractVersion::query()
                ->where('launch_campaign_creator_id', $assignment->id)
                ->count()
        );
    }

    public function test_cancelled_contract_is_stored_as_next_version(): void
    {
        $assignment = $this->assignment();

        $this->version($assignment, 1, 'Active Contract', 'active');

        $assignment->forceFill([
            'contract_status' => 'cancelled',
            'contract_version' => 2,
            'contract_cancelled_at' => now(),
            'contract_updated_at' => now(),
        ])->save();

        $this->version(
            $assignment->fresh(),
            2,
            'Active Contract',
            'cancelled'
        );

        $this->assertDatabaseHas('launch_creator_contract_versions', [
            'launch_campaign_creator_id' => $assignment->id,
            'version' => 1,
            'contract_status' => 'active',
        ]);

        $this->assertDatabaseHas('launch_creator_contract_versions', [
            'launch_campaign_creator_id' => $assignment->id,
            'version' => 2,
            'contract_status' => 'cancelled',
        ]);
    }

    private function assignment(): LaunchCampaignCreator
    {
        $campaignId = DB::table('launch_campaigns')->insertGetId([
            'name' => 'History Test Campaign',
            'code' => 'HISTORY-'.uniqid(),
            'tracking_code' => 'HISTORY-CAMPAIGN-'.uniqid(),
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $creatorId = DB::table('launch_creators')->insertGetId([
            'name' => 'History Test Creator',
            'code' => 'HISTORY-CREATOR-'.uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return LaunchCampaignCreator::query()->create([
            'campaign_id' => $campaignId,
            'creator_id' => $creatorId,
            'tracking_code' => 'HISTORY-'.uniqid(),
            'commission_type' => 'percentage',
            'commission_value' => 10,
            'status' => 'active',
            'contract_reference' => 'HISTORY-CONTRACT-'.uniqid(),
            'contract_title' => 'History Contract',
            'contract_content' => 'Contract history automated test',
            'contract_starts_at' => now()->subDay(),
            'contract_ends_at' => now()->addDays(10),
            'contract_status' => 'active',
            'contract_version' => 1,
            'contract_updated_at' => now(),
        ]);
    }

    private function version(
        LaunchCampaignCreator $assignment,
        int $version,
        string $title,
        string $status = 'active'
    ): LaunchCreatorContractVersion {
        return LaunchCreatorContractVersion::query()->create([
            'launch_campaign_creator_id' => $assignment->id,
            'version' => $version,
            'contract_reference' => $assignment->contract_reference,
            'contract_title' => $title,
            'contract_content' => $assignment->contract_content,
            'contract_starts_at' => $assignment->contract_starts_at,
            'contract_ends_at' => $assignment->contract_ends_at,
            'contract_status' => $status,
            'coupon_code' => $assignment->coupon_code,
            'commission_type' => $assignment->commission_type,
            'commission_value' => $assignment->commission_value,
            'snapshot' => [
                'version' => $version,
                'status' => $status,
                'title' => $title,
            ],
            'captured_at' => now(),
        ]);
    }
}
