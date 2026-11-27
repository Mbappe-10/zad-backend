<?php

namespace Tests\Feature\Api;

use App\Models\AppProfile;
use App\Models\FamilyStoreGrant;
use App\Models\FamilyStorePlan;
use App\Models\Product;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FamilyPlanDashboardTest extends TestCase
{
    use RefreshDatabase;

    private ProductiveFamily $family;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->family = ProductiveFamily::query()->create([
            'code' => 'PF-DASH1', 'owner_name' => 'أسرة اللوحة', 'phone' => '0553335555', 'status' => 'approved',
        ]);
        $this->store = Store::query()->create([
            'productive_family_id' => $this->family->id, 'name_ar' => 'مطبخ اللوحة',
            'slug' => 'dash-store', 'status' => 'active', 'is_open' => true,
        ]);
        $member = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $member->id, 'productive_family_id' => $this->family->id,
            'roles' => ['productive_family'], 'active_mode' => 'productive_family',
        ]);
        Sanctum::actingAs($member);
    }

    private function product(string $name): Product
    {
        return Product::query()->forceCreate([
            'store_id' => $this->store->id, 'name_ar' => $name, 'price' => 10, 'status' => 'active',
            'is_available' => true, 'preparation_minutes' => 20, 'package_size' => 'medium',
        ]);
    }

    public function test_status_shows_plan_end_and_usage(): void
    {
        $this->product('أ');
        $this->getJson('/api/v1/app/productive-family/storefront/status')->assertOk()
            ->assertJsonPath('data.plan', 'free')->assertJsonPath('data.ends_at', null)
            ->assertJsonPath('data.products.count', 1)->assertJsonPath('data.products.limit', 4)
            ->assertJsonPath('data.featured.limit', 0);

        $ends = now()->addDays(3)->startOfSecond();
        FamilyStoreGrant::query()->create([
            'productive_family_id' => $this->family->id, 'plan_code' => 'gold', 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => $ends,
        ]);
        $this->getJson('/api/v1/app/productive-family/storefront/status')->assertOk()
            ->assertJsonPath('data.plan', 'gold')->assertJsonPath('data.products.limit', 20)
            ->assertJsonPath('data.featured.limit', 6)
            ->assertJsonPath('data.ends_at', $ends->toISOString());
    }

    public function test_only_gold_features_dishes_up_to_its_limit_and_customers_see_them(): void
    {
        $a = $this->product('كبسة');
        $this->postJson("/api/v1/app/family/products/{$a->id}/featured", ['is_featured' => true])->assertForbidden();

        FamilyStoreGrant::query()->create([
            'productive_family_id' => $this->family->id, 'plan_code' => 'gold', 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(5),
        ]);
        $gold = FamilyStorePlan::query()->where('code', 'gold')->firstOrFail();
        $gold->update(['capabilities' => [...$gold->capabilities, 'featured_products' => 1]]);

        $this->postJson("/api/v1/app/family/products/{$a->id}/featured", ['is_featured' => true])
            ->assertOk()->assertJsonPath('data.is_featured', true);
        $b = $this->product('مندي');
        $this->postJson("/api/v1/app/family/products/{$b->id}/featured", ['is_featured' => true])->assertStatus(422);
        $this->getJson('/api/v1/app/family/products')->assertJsonPath('meta.featured_count', 1)->assertJsonPath('meta.featured_limit', 1);

        $catalog = $this->getJson("/api/v1/app/stores/{$this->store->id}/products")->assertOk()
            ->assertJsonPath('store.plan', 'gold')->assertJsonPath('store.featured_enabled', true)->json('products');
        $this->assertTrue(collect($catalog)->firstWhere('id', $a->id)['is_featured']);
        $this->assertFalse(collect($catalog)->firstWhere('id', $b->id)['is_featured']);
    }
}
