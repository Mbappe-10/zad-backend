<?php

namespace Tests\Feature\Api;

use App\Models\User;
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
            'name' => 'عميل اختبار',
            'phone' => '0500000000',
            'status' => 'pending',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'عميل اختبار')
            ->json('data');

        $id = $created['id'];

        $this->getJson('/api/admin/customers')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $id);

        $this->putJson("/api/admin/customers/{$id}", [
            'name' => 'عميل محدث',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'عميل محدث');

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
                    'appNameAr' => 'زاد',
                    'timezone' => 'Asia/Riyadh',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.general.appNameAr', 'زاد');

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
            'subject' => 'مشكلة اختبار',
            'requester_name' => 'مستخدم',
            'priority' => 'medium',
            'status' => 'open',
        ])
            ->assertCreated()
            ->json('data');

        $this->postJson("/api/admin/support/tickets/{$ticket['id']}/reply", [
            'message' => 'تم استلام طلبك.',
        ])
            ->assertOk()
            ->assertJsonPath('data.messages_count', 1)
            ->assertJsonPath('data.messages.0.message', 'تم استلام طلبك.');
    }

    public function test_wallet_actions_and_content_upload_work(): void
    {
        $wallet = $this->postJson('/api/admin/wallets', [
            'owner_name' => 'محفظة اختبار',
            'available_balance' => 100,
            'status' => 'active',
        ])->assertCreated()->json('data');

        $this->postJson("/api/admin/wallets/{$wallet['id']}/credit", [
            'amount' => 25,
            'reason' => 'إضافة تجريبية',
        ])
            ->assertOk()
            ->assertJsonPath('data.available_balance', 125);

        $this->postJson("/api/admin/wallets/{$wallet['id']}/debit", [
            'amount' => 10,
            'reason' => 'خصم تجريبي',
        ])
            ->assertOk()
            ->assertJsonPath('data.available_balance', 115);

        $this->getJson("/api/admin/wallets/{$wallet['id']}/transactions")
            ->assertOk()
            ->assertJsonCount(2, 'transactions');

        $this->patchJson("/api/admin/wallets/{$wallet['id']}/freeze")
            ->assertOk()
            ->assertJsonPath('data.is_frozen', true);

        $this->patchJson("/api/admin/wallets/{$wallet['id']}/unfreeze")
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
