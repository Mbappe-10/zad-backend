<?php

namespace Tests\Feature\Api;

use App\Models\AppSetting;
use App\Models\PlatformControl;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnerMasterSwitchesTest extends TestCase
{
    use RefreshDatabase;

    private function setPlatform(array $changes): void
    {
        $current = PlatformControl::query()
            ->where('section', 'platform')
            ->value('value') ?? [];

        PlatformControl::query()->updateOrCreate(
            ['section' => 'platform'],
            ['value' => array_merge($current, $changes)]
        );
    }
public function test_new_orders_master_switch_blocks_before_order_validation(): void
    {
        $this->setPlatform(['newOrdersEnabled' => false]);

        $this->postJson('/api/v1/app/orders', [])
            ->assertStatus(423);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_new_orders_master_switch_allows_request_to_reach_validation(): void
    {
        $this->setPlatform(['newOrdersEnabled' => true]);

        $this->postJson('/api/v1/app/orders', [])
            ->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }
}