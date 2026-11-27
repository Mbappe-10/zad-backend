<?php

namespace Tests\Feature\Api;

use App\Models\AppProfile;
use App\Models\FamilyStoreGrant;
use App\Models\FamilyStorePlan;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FamilySalesAndLimitsTest extends TestCase
{
    use RefreshDatabase;

    private ProductiveFamily $family;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->family = ProductiveFamily::query()->create([
            'code' => 'PF-SALES1', 'owner_name' => 'أسرة المبيعات', 'phone' => '0553334444', 'status' => 'approved',
        ]);
        $this->store = Store::query()->create([
            'productive_family_id' => $this->family->id, 'name_ar' => 'مطبخ المبيعات',
            'slug' => 'sales-store', 'status' => 'active', 'is_open' => true,
        ]);
        $member = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $member->id, 'productive_family_id' => $this->family->id,
            'roles' => ['productive_family'], 'active_mode' => 'productive_family',
        ]);
        Sanctum::actingAs($member);
    }

    private function grant(string $plan): void
    {
        FamilyStoreGrant::query()->create([
            'productive_family_id' => $this->family->id, 'plan_code' => $plan, 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(5),
        ]);
    }

    private function product(int $n): array
    {
        return [
            'name_ar' => 'طبق '.$n, 'price' => 10, 'preparation_minutes' => 20,
            'package_size' => 'medium', 'is_available' => true,
        ];
    }

    private function order(string $number, float $total, ?string $phone = '0551110000', string $when = 'now'): int
    {
        $at = now()->parse($when);
        $id = DB::table('orders')->insertGetId([
            'number' => $number, 'store_id' => $this->store->id, 'status' => 'delivered', 'payment_status' => 'paid',
            'subtotal' => $total, 'total' => $total, 'contact_phone' => $phone,
            'delivered_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $id, 'product_name' => 'كبسة', 'quantity' => 2, 'unit_price' => $total / 2, 'total' => $total,
            'created_at' => $at, 'updated_at' => $at,
        ]);

        return $id;
    }

    public function test_product_limit_follows_the_plan_and_is_editable(): void
    {
        $this->assertSame(4, FamilyStorePlan::query()->where('code', 'free')->firstOrFail()->capabilities['max_products']);
        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/app/family/products', $this->product($i))->assertCreated();
        }
        $this->postJson('/api/v1/app/family/products', $this->product(5))->assertStatus(422);
        $this->getJson('/api/v1/app/family/products')->assertOk()
            ->assertJsonPath('meta.count', 4)->assertJsonPath('meta.limit', 4)->assertJsonPath('meta.plan', 'free');

        $this->grant('silver');
        $this->postJson('/api/v1/app/family/products', $this->product(5))->assertCreated();
        $this->getJson('/api/v1/app/family/products')->assertJsonPath('meta.limit', 10);

        // The owner sets 0 = unlimited for silver from the dashboard.
        $member = auth()->user();
        $owner = User::factory()->create();
        $owner->forceFill(['is_platform_owner' => true])->save();
        Sanctum::actingAs($owner);
        $this->patchJson('/api/admin/family-store-plans/silver', ['capabilities' => ['max_products' => 0]])
            ->assertOk()->assertJsonPath('data.capabilities.max_products', 0)
            ->assertJsonPath('data.capabilities.banner', true);
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/app/family/products')->assertJsonPath('meta.unlimited', true);
    }

    public function test_free_plan_sees_totals_only_and_cannot_export(): void
    {
        $this->order('ZS-1', 40);
        $this->order('ZS-2', 25, '0552220000');

        $data = $this->getJson('/api/v1/app/family/sales')->assertOk()->json('data');
        $this->assertSame('free', $data['plan']);
        $this->assertSame(65.0, (float) $data['summary']['today']['sales']);
        $this->assertSame(2, $data['summary']['today']['orders']);
        $this->assertCount(2, $data['recent']);
        $this->assertNull($data['insights']);
        $this->assertNull($data['pro']);
        $this->assertSame('silver', $data['unlock']['insights']);
        $this->assertSame('gold', $data['unlock']['pro']);

        $this->getJson('/api/v1/app/family/sales/export?from='.now('Asia/Riyadh')->toDateString().'&to='.now('Asia/Riyadh')->toDateString())
            ->assertForbidden();
    }

    public function test_gold_gets_insights_customers_comparison_and_a_sales_file(): void
    {
        $this->grant('gold');
        $this->order('ZS-10', 40, '0551110000');
        $this->order('ZS-11', 30, '0551110000', '-2 days');
        $this->order('ZS-12', 20, '0559990000', '-3 days');

        $data = $this->getJson('/api/v1/app/family/sales')->assertOk()->json('data');
        $this->assertTrue($data['features']['insights']);
        $this->assertCount(30, $data['insights']['daily']);
        $this->assertSame('كبسة', $data['insights']['top_products'][0]['name']);
        $this->assertSame(6, $data['insights']['top_products'][0]['quantity']);
        $this->assertCount(24, $data['insights']['peak_hours']);
        $this->assertSame(2, $data['pro']['customers']['unique']);
        $this->assertSame(1, $data['pro']['customers']['returning']);
        $this->assertStringEndsWith('••00', $data['pro']['customers']['top'][0]['label']);

        $today = now('Asia/Riyadh')->toDateString();
        $from = now('Asia/Riyadh')->subDays(7)->toDateString();
        $file = $this->getJson("/api/v1/app/family/sales/export?from={$from}&to={$today}")->assertOk()->json();
        $csv = base64_decode($file['content_base64']);
        $this->assertSame(3, $file['orders']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('ZS-12', $csv);
        $this->assertStringContainsString('"90.00"', $csv);
    }
}
