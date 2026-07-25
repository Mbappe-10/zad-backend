<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_endpoints_require_authentication(): void
    {
        $this->getJson('/api/finance/summary')->assertUnauthorized();
        $this->getJson('/api/finance/wallets')->assertUnauthorized();
        $this->getJson('/api/finance/payments')->assertUnauthorized();
    }

    public function test_authenticated_user_can_open_finance_summary(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'status' => 'active',
            'is_approved' => true,
        ]));

        $this->getJson('/api/finance/summary')->assertOk();
    }
}
