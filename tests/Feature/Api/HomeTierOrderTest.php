<?php

namespace Tests\Feature\Api;

use App\Models\FamilyStoreGrant;
use App\Models\ProductiveFamily;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Home feed: gold stores' products first, then silver, then free — each carries its tier. */
class HomeTierOrderTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $code, ?string $plan): int
    {
        $family = ProductiveFamily::query()->create(['code' => 'PF-'.$code, 'owner_name' => $code, 'phone' => '05500000'.rand(10, 99), 'status' => 'approved']);
        $store = Store::query()->create(['productive_family_id' => $family->id, 'name_ar' => 'متجر '.$code, 'slug' => 'st-'.strtolower($code), 'status' => 'active', 'is_open' => true]);
        if ($plan) {
            FamilyStoreGrant::query()->create(['productive_family_id' => $family->id, 'plan_code' => $plan, 'source' => 'owner_grant',
                'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30)]);
        }

        return DB::table('products')->insertGetId(['store_id' => $store->id, 'name_ar' => 'منتج '.$code, 'price' => 10,
            'status' => 'active', 'is_available' => true, 'updated_at' => now()->subMinutes(match ($plan) { 'gold' => 30, 'silver' => 20, default => 1 })]);
    }

    public function test_gold_then_silver_then_free_even_when_free_is_newer(): void
    {
        $free = $this->store('FREE', null);
        $silver = $this->store('SILVER', 'silver');
        $gold = $this->store('GOLD', 'gold');

        $products = $this->getJson('/api/v1/app/bootstrap')->assertOk()->json('featured_products');
        $this->assertSame([$gold, $silver, $free], array_column($products, 'id'));
        $this->assertSame(['gold', 'silver', 'free'], array_column($products, 'tier'));
    }
}
