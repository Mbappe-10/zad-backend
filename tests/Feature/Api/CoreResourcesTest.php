<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoreResourcesTest extends TestCase
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

    public function test_city_crud_cycle(): void
    {
        $created = $this->postJson('/api/core/cities', [
            'code' => 'MKK',
            'name_ar' => 'مكة المكرمة',
            'name_en' => 'Makkah',
            'is_active' => true,
            'delivery_base_fee' => 10,
        ])->assertCreated()->json('data');

        $id = $created['id'];

        $this->getJson('/api/core/cities?search=MKK')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->patchJson("/api/core/cities/{$id}", [
            'delivery_base_fee' => 12,
        ])->assertOk()->assertJsonPath('data.delivery_base_fee', 12);

        $this->deleteJson("/api/core/cities/{$id}")->assertOk();
        $this->getJson("/api/core/cities/{$id}")->assertNotFound();
    }

    public function test_unknown_resource_returns_not_found(): void
    {
        $this->getJson('/api/core/not-a-resource')->assertNotFound();
    }
}
