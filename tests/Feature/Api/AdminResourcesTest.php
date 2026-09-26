<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminResourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create([
            'status' => 'active',
            'is_approved' => true,
        ]));
    }

    public function test_admin_resource_crud_and_status_actions(): void
    {
        $created = $this->postJson('/api/admin/customers', [
            'name' => 'ط¹ظ…ظٹظ„ ط§ط®طھط¨ط§ط±',
            'phone' => '0500000000',
            'status' => 'pending',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'ط¹ظ…ظٹظ„ ط§ط®طھط¨ط§ط±')
            ->json('data');

        $id = $created['id'];

        $this->getJson('/api/admin/customers')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $id);

        $this->putJson("/api/admin/customers/{$id}", [
            'name' => 'ط¹ظ…ظٹظ„ ظ…ط­ط¯ط«',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'ط¹ظ…ظٹظ„ ظ…ط­ط¯ط«');

        $this->postJson("/api/admin/customers/{$id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->getJson('/api/admin/customers/stats')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('active', 1);

        $this->deleteJson("/api/admin/customers/{$id}")
            ->assertOk();

        $this->getJson("/api/admin/customers/{$id}")
            ->assertNotFound();
    }

    public function test_settings_and_dashboard_endpoints(): void
    {
        $this->putJson('/api/admin/settings', [
            'settings' => [
                'general' => [
                    'appNameAr' => 'ط²ط§ط¯ ط³ظٹظ†ظƒ',
                    'timezone' => 'Asia/Riyadh',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.general.appNameAr', 'ط²ط§ط¯ ط³ظٹظ†ظƒ');

        $this->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.general.timezone', 'Asia/Riyadh');

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('system_health.api', 'healthy')
            ->assertJsonStructure([
                'kpis',
                'trends',
                'recent_orders',
                'recent_activities',
                'alerts',
                'system_health',
            ]);
    }

    public function test_support_ticket_reply_is_persisted(): void
    {
        $ticket = $this->postJson('/api/admin/support/tickets', [
            'subject' => 'ظ…ط´ظƒظ„ط© ط§ط®طھط¨ط§ط±',
            'requester_name' => 'ظ…ط³طھط®ط¯ظ…',
            'priority' => 'medium',
            'status' => 'open',
        ])
            ->assertCreated()
            ->json('data');

        $this->postJson("/api/admin/support/tickets/{$ticket['id']}/reply", [
            'message' => 'طھظ… ط§ط³طھظ„ط§ظ… ط·ظ„ط¨ظƒ.',
        ])
            ->assertOk()
            ->assertJsonPath('data.messages_count', 1)
            ->assertJsonPath('data.messages.0.message', 'طھظ… ط§ط³طھظ„ط§ظ… ط·ظ„ط¨ظƒ.');
    }

    public function test_wallet_actions_and_content_upload_work(): void
    {
        $owner = User::factory()->create();

        $wallet = Wallet::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'currency' => 'SAR',
            'available_balance' => 100,
            'pending_balance' => 0,
            'is_frozen' => false,
            'status' => 'active',
        ]);

        $this->postJson("/api/admin/wallets/{$wallet->id}/adjust", [
            'direction' => 'credit',
            'amount' => 25,
            'reason' => 'Test credit',
        ])->assertCreated();

        $this->assertSame('125.00', $wallet->fresh()->available_balance);

        $this->postJson("/api/admin/wallets/{$wallet->id}/adjust", [
            'direction' => 'debit',
            'amount' => 10,
            'reason' => 'Test debit',
        ])->assertCreated();

        $this->assertSame('115.00', $wallet->fresh()->available_balance);

        $this->getJson("/api/admin/wallets/{$wallet->id}/transactions")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->patchJson("/api/admin/wallets/{$wallet->id}/status", [
            'status' => 'frozen',
            'reason' => 'Test freeze',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_frozen', true);

        $this->patchJson("/api/admin/wallets/{$wallet->id}/status", [
            'status' => 'active',
            'reason' => 'Test unfreeze',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_frozen', false);

        Storage::fake('public');

        $this->post('/api/admin/content/upload', [
            'file' => UploadedFile::fake()->create(
                'guide.pdf',
                100,
                'application/pdf',
            ),
        ])
            ->assertCreated()
            ->assertJsonStructure(['path', 'url']);
    }
}
