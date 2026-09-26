<?php

namespace Tests\Feature\Api;

use App\Models\PlatformControl;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ControlCenterTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->create();

        $user->forceFill([
            'is_platform_owner' => true,
        ])->save();

        return $user->fresh();
    }

    public function test_normal_authenticated_user_cannot_access_control_center(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/control-center')
            ->assertForbidden();

        $this->patchJson('/api/admin/control-center/platform', [
            'value' => [
                'newOrdersEnabled' => false,
            ],
            'confirmation' => true,
        ])->assertForbidden();

        $this->postJson('/api/admin/control-center/actions/orders-off', [
            'confirmation' => true,
        ])->assertForbidden();
    }

    public function test_platform_owner_can_load_control_center(): void
    {
        Sanctum::actingAs($this->owner());

        $response = $this->getJson('/api/admin/control-center');

        $response
            ->assertOk()
            ->assertJsonPath('meta.isOwner', true)
            ->assertJsonPath('meta.canManagePlatform', true);

        $this->assertDatabaseHas('platform_controls', [
            'section' => 'platform',
        ]);

        $this->assertDatabaseHas('platform_controls', [
            'section' => 'finance',
        ]);

        $this->assertDatabaseHas('platform_controls', [
            'section' => 'app_updates',
        ]);
    }

    public function test_sensitive_section_requires_confirmation(): void
    {
        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/control-center')
            ->assertOk();

        $this->patchJson('/api/admin/control-center/finance', [
            'value' => [
                'familyMinimumWithdrawalAmount' => 90,
            ],
        ])->assertStatus(422);
    }

    public function test_owner_can_update_finance_settings_and_value_persists(): void
    {
        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/control-center')
            ->assertOk();

        $this->patchJson('/api/admin/control-center/finance', [
            'value' => [
                'familyMinimumWithdrawalAmount' => 90,
                'courierMinimumWithdrawalAmount' => 120,
            ],
            'confirmation' => true,
            'reason' => 'Automated control center audit',
        ])->assertOk();

        $finance = PlatformControl::query()
            ->where('section', 'finance')
            ->firstOrFail();

        $this->assertSame(
            90,
            $finance->value['familyMinimumWithdrawalAmount']
        );

        $this->assertSame(
            120,
            $finance->value['courierMinimumWithdrawalAmount']
        );

        $this->getJson('/api/admin/control-center')
            ->assertOk()
            ->assertJsonFragment([
                'familyMinimumWithdrawalAmount' => 90,
                'courierMinimumWithdrawalAmount' => 120,
            ]);
    }

    public function test_owner_action_changes_order_flag_and_records_history(): void
    {
        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/control-center')
            ->assertOk();

        $this->postJson('/api/admin/control-center/actions/orders-off', [
            'confirmation' => true,
            'reason' => 'Automated control center audit',
        ])->assertOk()
          ->assertJsonPath('action', 'orders-off')
          ->assertJsonPath('result.key', 'newOrdersEnabled')
          ->assertJsonPath('result.value', false);

        $platform = PlatformControl::query()
            ->where('section', 'platform')
            ->firstOrFail();

        $this->assertFalse(
            $platform->value['newOrdersEnabled']
        );

        $history = PlatformControl::query()
            ->where('section', 'action_history')
            ->firstOrFail();

        $items = $history->value['items'] ?? [];

        $this->assertNotEmpty($items);
        $this->assertSame('orders-off', $items[0]['action']);
    }

    public function test_action_requires_explicit_confirmation(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/admin/control-center/actions/orders-off', [])
            ->assertStatus(422);
    }

    public function test_unknown_section_and_action_are_rejected(): void
    {
        Sanctum::actingAs($this->owner());

        $this->patchJson('/api/admin/control-center/not-real', [
            'value' => ['enabled' => true],
            'confirmation' => true,
        ])->assertNotFound();

        $this->postJson('/api/admin/control-center/actions/not-real', [
            'confirmation' => true,
        ])->assertNotFound();
    }
}