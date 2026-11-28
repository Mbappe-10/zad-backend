<?php

namespace Tests\Feature\Api;

use App\Models\AppProfile;
use App\Models\CashierMaterial;
use App\Models\FamilyCashierGrant;
use App\Models\FamilyStoreGrant;
use App\Models\FamilyStorePlan;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashierInventoryTest extends TestCase
{
    use RefreshDatabase;

    private ProductiveFamily $family;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $cashierPlan = \App\Models\FamilyStorePlan::query()->where('code', 'cashier_pro')->first();
        $cashierPlan?->update(['capabilities' => array_replace((array) $cashierPlan->capabilities, ['trial_enabled' => false])]);
        $this->family = ProductiveFamily::query()->create([
            'code' => 'PF-INV1', 'owner_name' => 'أسرة المخزون', 'phone' => '0551114444', 'status' => 'approved',
        ]);
        $this->store = Store::query()->create([
            'productive_family_id' => $this->family->id, 'name_ar' => 'سوبو', 'slug' => 'inv-store', 'status' => 'active', 'is_open' => true,
        ]);
        $member = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $member->id, 'productive_family_id' => $this->family->id,
            'roles' => ['productive_family'], 'active_mode' => 'productive_family',
        ]);
        FamilyStoreGrant::query()->create([
            'productive_family_id' => $this->family->id, 'plan_code' => 'gold', 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(10),
        ]);
        Sanctum::actingAs($member);
    }

    private function product(string $name, float $price = 10): Product
    {
        return Product::query()->forceCreate([
            'store_id' => $this->store->id, 'name_ar' => $name, 'price' => $price, 'status' => 'active',
            'is_available' => true, 'preparation_minutes' => 10, 'package_size' => 'small',
        ]);
    }

    public function test_tracked_stock_shows_to_customers_and_every_paid_sale_takes_from_it(): void
    {
        $p = $this->product('حبحبوه', 25);
        // A normal (gold) store has no quantities: products are just on or off.
        $this->patchJson("/api/v1/app/family/cashier/products/{$p->id}/stock", ['stock_quantity' => 6])->assertForbidden();
        // Items not in the store need Pro.
        $this->postJson('/api/v1/app/family/cashier/sales', ['items' => [['name' => 'ثلج', 'unit_price' => 2, 'quantity' => 1]], 'payment_method' => 'cash', 'channel' => 'shop'])
            ->assertStatus(422);
        $grant = FamilyCashierGrant::query()->create(['productive_family_id' => $this->family->id, 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(5)]);
        $this->patchJson("/api/v1/app/family/cashier/products/{$p->id}/stock", ['stock_quantity' => 6])
            ->assertOk()->assertJsonPath('data.stock', 6)->assertJsonPath('data.track_stock', true);
        $catalog = $this->getJson("/api/v1/app/stores/{$this->store->id}/products")->assertOk()->json('products');
        $this->assertSame(6, collect($catalog)->firstWhere('id', $p->id)['stock_left']);

        $this->postJson('/api/v1/app/family/cashier/sales', ['items' => [['product_id' => $p->id, 'quantity' => 2]], 'payment_method' => 'cash', 'channel' => 'shop'])
            ->assertCreated();
        $this->assertSame(4, $p->fresh()->stock_quantity);
        $this->postJson('/api/v1/app/family/cashier/sales', ['items' => [['product_id' => $p->id, 'quantity' => 5]], 'payment_method' => 'cash', 'channel' => 'shop'])
            ->assertStatus(422)->assertJsonPath('message', 'باقي 4 فقط من حبحبوه.');

        // A paid online order takes its items too, once.
        $orderId = DB::table('orders')->insertGetId([
            'number' => 'ORD-STK', 'store_id' => $this->store->id, 'status' => 'pending', 'payment_status' => 'unpaid',
            'subtotal' => 75, 'total' => 75, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert(['order_id' => $orderId, 'product_id' => $p->id, 'product_name' => 'حبحبوه', 'quantity' => 3,
            'unit_price' => 25, 'total' => 75, 'created_at' => now(), 'updated_at' => now()]);
        $order = Order::query()->findOrFail($orderId);
        $order->update(['payment_status' => Order::PAYMENT_PAID]);
        $order->update(['status' => 'preparing']);
        $this->assertSame(1, $p->fresh()->stock_quantity);

        // Pro ended: the quantity is kept but frozen; nothing is blocked or taken.
        $grant->update(['ends_at' => now()->subMinute()]);
        $this->assertNull(collect($this->getJson("/api/v1/app/stores/{$this->store->id}/products")->json('products'))->firstWhere('id', $p->id)['stock_left']);
        $this->postJson('/api/v1/app/family/cashier/sales', ['items' => [['product_id' => $p->id, 'quantity' => 5]], 'payment_method' => 'cash', 'channel' => 'shop'])
            ->assertCreated();
        $this->assertSame(1, $p->fresh()->stock_quantity);
        $this->assertTrue((bool) $p->fresh()->track_stock);
    }

    public function test_cashier_only_products_are_hidden_online_and_outside_the_plan_limit(): void
    {
        $gold = FamilyStorePlan::query()->where('code', 'gold')->firstOrFail();
        $gold->update(['capabilities' => [...$gold->capabilities, 'max_products' => 1]]);
        $body = ['name_ar' => 'كبسة', 'price' => 30, 'is_available' => true, 'package_size' => 'medium', 'preparation_minutes' => 30];
        $this->postJson('/api/v1/app/family/products', $body)->assertCreated();
        $this->postJson('/api/v1/app/family/products', [...$body, 'name_ar' => 'مندي'])->assertStatus(422);
        $cashierOnly = $this->postJson('/api/v1/app/family/products', [...$body, 'name_ar' => 'ثلج المحل', 'cashier_only' => true])
            ->assertCreated()->assertJsonPath('data.cashier_only', true)->json('data.id');

        $ids = collect($this->getJson("/api/v1/app/stores/{$this->store->id}/products")->json('products'))->pluck('id');
        $this->assertNotContains($cashierOnly, $ids);
        $this->getJson('/api/v1/app/family/products')->assertJsonPath('meta.count', 1)->assertJsonPath('meta.cashier_only_count', 1);
        // Back to online is refused while the online limit is full.
        $this->patchJson("/api/v1/app/family/cashier/products/{$cashierOnly}/stock", ['cashier_only' => false])->assertStatus(422);
    }

    public function test_pro_materials_recipe_and_production_cost(): void
    {
        $this->postJson('/api/v1/app/family/cashier/materials', ['name' => 'سكر', 'unit' => 'كيس', 'quantity' => 2, 'unit_cost' => 5])->assertForbidden();
        FamilyCashierGrant::query()->create(['productive_family_id' => $this->family->id, 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(5)]);

        $sugar = $this->postJson('/api/v1/app/family/cashier/materials', ['name' => 'سكر', 'unit' => 'كيس', 'quantity' => 2, 'unit_cost' => 5])
            ->assertCreated()->json('data.id');
        $cups = $this->postJson('/api/v1/app/family/cashier/materials', ['name' => 'كاسات', 'kind' => 'packaging', 'unit' => 'حبة', 'quantity' => 100, 'unit_cost' => 0.2, 'low_alert' => 95])
            ->assertCreated()->json('data.id');
        $p = $this->product('كركديه', 6);
        $this->putJson("/api/v1/app/family/cashier/products/{$p->id}/recipe", ['items' => [
            ['material_id' => $sugar, 'yield' => 6], ['material_id' => $cups, 'per_unit' => 1],
        ]])->assertOk()->assertJsonPath('data.unit_cost', 1.03)->assertJsonPath('data.profit', 4.97);

        $this->postJson('/api/v1/app/family/cashier/productions', ['product_id' => $p->id, 'quantity' => 6])->assertCreated()
            ->assertJsonPath('data.quantity', 6);
        $this->assertSame(6, $p->fresh()->stock_quantity);
        $this->assertEqualsWithDelta(1.0, CashierMaterial::query()->find($sugar)->quantity, 0.001);
        $this->assertEqualsWithDelta(94.0, CashierMaterial::query()->find($cups)->quantity, 0.001);

        $snap = $this->getJson('/api/v1/app/family/cashier/inventory')->assertOk()->json('data');
        $this->assertTrue(collect($snap['materials'])->firstWhere('id', $cups)['low']);
        $this->get('/api/v1/app/family/cashier/inventory/export?format=xlsx')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->get('/api/v1/app/family/cashier/inventory/export?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
