<?php

namespace Tests\Feature\Api;

use App\Models\AppProfile;
use App\Models\CashierStaff;
use App\Models\FamilyCashierGrant;
use App\Models\FamilyStoreGrant;
use App\Models\Product;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashierStaffTest extends TestCase
{
    use RefreshDatabase;

    private ProductiveFamily $family;
    private Store $store;
    private User $owner;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $cashierPlan = \App\Models\FamilyStorePlan::query()->where('code', 'cashier_pro')->first();
        $cashierPlan?->update(['capabilities' => array_replace((array) $cashierPlan->capabilities, ['trial_enabled' => false])]);
        Storage::fake('r2_contracts');
        $this->family = ProductiveFamily::query()->create([
            'code' => 'PF-STAFF', 'owner_name' => 'أم سارة', 'phone' => '0551117777', 'status' => 'approved',
        ]);
        $this->store = Store::query()->create([
            'productive_family_id' => $this->family->id, 'name_ar' => 'سوبو',
            'slug' => 'staff-store', 'status' => 'active', 'is_open' => true,
        ]);
        $this->owner = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $this->owner->id, 'productive_family_id' => $this->family->id,
            'roles' => ['productive_family'], 'active_mode' => 'productive_family',
        ]);
        $this->product = Product::query()->forceCreate([
            'store_id' => $this->store->id, 'name_ar' => 'حبحبوه', 'price' => 25, 'status' => 'active',
            'is_available' => true, 'preparation_minutes' => 10, 'package_size' => 'small',
        ]);
        FamilyStoreGrant::query()->create([
            'productive_family_id' => $this->family->id, 'plan_code' => 'gold', 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30),
        ]);
        Sanctum::actingAs($this->owner);
    }

    private function pro(): void
    {
        FamilyCashierGrant::query()->create([
            'productive_family_id' => $this->family->id, 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30),
        ]);
    }

    private function addStaff(string $name, string $pin = '4817', array $extra = []): array
    {
        return $this->postJson('/api/v1/app/family/cashier/staff', array_replace(
            ['name' => $name, 'role' => 'cashier', 'pin' => $pin], $extra))->assertCreated()->json('data');
    }

    private function sell(string $base, array $headers = [], array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($headers)->postJson($base.'/sales', array_replace([
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'payment_method' => 'cash', 'channel' => 'shop',
        ], $extra));
    }

    public function test_staff_is_pro_only_and_the_owner_adds_up_to_the_limit(): void
    {
        $this->getJson('/api/v1/app/family/cashier/staff')->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.limit', 7)
            ->assertJsonPath('data.staff.0.role', 'owner');
        $this->postJson('/api/v1/app/family/cashier/staff', ['name' => 'نوف', 'role' => 'cashier', 'pin' => '1234'])->assertForbidden();

        $this->pro();
        $noof = $this->addStaff('نوف');
        $this->assertSame(['sell'], $noof['permissions']);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{6}$/', $noof['invite']['code']);
        for ($i = 2; $i <= 7; $i++) {
            $this->addStaff('موظفة '.$i);
        }
        $this->postJson('/api/v1/app/family/cashier/staff', ['name' => 'الثامنة', 'role' => 'cashier', 'pin' => '1234'])
            ->assertStatus(422);
        $this->assertNull(CashierStaff::query()->first()->getAttribute('pin_plain'));
        $this->assertArrayNotHasKey('pin_hash', $noof);
    }

    public function test_employee_joins_with_the_invite_and_every_sale_carries_her_name(): void
    {
        $this->pro();
        $noof = $this->addStaff('نوف');
        $employee = User::factory()->create();
        Sanctum::actingAs($employee);

        $this->postJson('/api/v1/app/staff/join', ['code' => 'WRONG1'])->assertStatus(422);
        $this->postJson('/api/v1/app/staff/join', ['code' => strtolower($noof['invite']['code'])])->assertOk()
            ->assertJsonPath('data.store_name', 'سوبو')->assertJsonPath('data.role_label', 'كاشير');
        // One use only.
        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/app/staff/join', ['code' => $noof['invite']['code']])->assertStatus(422);

        Sanctum::actingAs($employee);
        $base = '/api/v1/app/staff/'.$noof['id'].'/cashier';
        $this->getJson($base)->assertOk()->assertJsonPath('data.me.name', 'نوف')->assertJsonPath('data.me.owner', false);
        $this->getJson($base.'/products')->assertOk()->assertJsonPath('data.0.name_ar', 'حبحبوه');
        $this->sell($base)->assertStatus(422); // no open shift
        $this->postJson($base.'/shift/open', ['opening_cash' => 100])->assertCreated();
        $this->sell($base, [], ['discount' => 5])->assertForbidden(); // no discount permission
        $receipt = $this->sell($base)->assertCreated()->assertJsonPath('data.staff_name', 'نوف')->json('data');
        $this->get(parse_url($receipt['url'], PHP_URL_PATH))->assertOk()->assertSee('نوف');
        $this->getJson($base.'/day')->assertForbidden(); // no reports permission
        $this->patchJson($base.'/products/'.$this->product->id.'/stock', ['stock_quantity' => 5])->assertForbidden();

        // The owner sees who sold what; the employee never reaches owner pages.
        $this->getJson('/api/v1/app/family/cashier/staff')->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->sell('/api/v1/app/family/cashier')->assertCreated()->assertJsonPath('data.staff_name', 'أم سارة');
        $report = $this->getJson('/api/v1/app/family/cashier/staff-sales')->assertOk()->json('data');
        $byName = collect($report['staff'])->keyBy('name');
        $this->assertEquals(50, $byName['نوف']['sales_total']);
        $this->assertEquals(1, $byName['نوف']['sales_count']);
        $this->assertEquals(50, $byName['أم سارة']['sales_total']);
        $this->assertEquals(50, $byName['نوف']['average_ticket']);
        $this->assertGreaterThan(0, $byName['نوف']['score']);
        $this->patchJson('/api/v1/app/family/cashier/staff/'.$noof['id'], ['rating' => 5, 'rating_note' => 'سريعة ومرتبة'])->assertOk()
            ->assertJsonPath('data.rating', 5);
        $this->getJson('/api/v1/app/family/cashier/staff-sales')->assertJsonPath('data.staff.0.rating', 5);
        $this->getJson('/api/v1/app/family/cashier/staff-sales?staff_id='.$noof['id'])->assertJsonPath('data.top_products.0.name', 'حبحبوه')
            ->assertJsonPath('data.top_products.0.quantity', 2);
        $products = $this->getJson('/api/v1/app/family/cashier/product-sales')->assertOk()->json('data');
        $this->assertSame('حبحبوه', $products['products'][0]['name']);
        $this->assertEquals(4, $products['products'][0]['quantity']);
        $this->assertEquals(100, $products['products'][0]['share']);
        $this->getJson('/api/v1/app/family/cashier/staff-sales?staff_id='.$noof['id'])->assertOk()
            ->assertJsonCount(1, 'data.receipts')->assertJsonPath('data.receipts.0.staff_name', 'نوف');

        // Removing her ends access; her past sales stay under her name.
        $this->deleteJson('/api/v1/app/family/cashier/staff/'.$noof['id'])->assertOk();
        Sanctum::actingAs($employee);
        $this->getJson($base)->assertForbidden();
        $this->assertSame(1, DB::table('sale_receipts')->where('staff_name', 'نوف')->count());
    }

    public function test_shop_ipad_stays_locked_until_an_employee_types_her_pin_and_shifts_balance_cash(): void
    {
        $this->pro();
        $noof = $this->addStaff('نوف', '4817');
        $this->postJson('/api/v1/app/family/cashier/devices', ['kind' => 'cashier', 'name' => 'آيباد المحل'])
            ->assertStatus(422); // owner sets her own PIN first
        $ownerRow = collect($this->getJson('/api/v1/app/family/cashier/staff')->json('data.staff'))->firstWhere('role', 'owner');
        $this->patchJson('/api/v1/app/family/cashier/staff/'.$ownerRow['id'], ['pin' => '9999'])->assertOk();
        $token = $this->postJson('/api/v1/app/family/cashier/devices', ['kind' => 'cashier', 'name' => 'آيباد المحل'])
            ->assertCreated()->json('data.token');

        // The iPad has no Zad login of its own.
        $this->app['auth']->forgetGuards();
        $device = ['X-Cashier-Device' => $token];
        $people = $this->withHeaders($device)->getJson('/api/v1/cashier-device/me')->assertOk()
            ->assertJsonPath('data.store.name', 'سوبو')->assertJsonPath('data.unlocked', null)->json('data.people');
        $this->assertSame(['أم سارة', 'نوف'], array_column($people, 'name'));
        $this->sell('/api/v1/cashier-device/cashier', $device)->assertStatus(423);
        $this->withHeaders(['X-Cashier-Device' => 'not-a-device'])->getJson('/api/v1/cashier-device/me')->assertStatus(401);

        for ($i = 0; $i < 4; $i++) {
            $this->withHeaders($device)->postJson('/api/v1/cashier-device/unlock', ['staff_id' => $noof['id'], 'pin' => '0000'])->assertStatus(422);
        }
        $this->withHeaders($device)->postJson('/api/v1/cashier-device/unlock', ['staff_id' => $noof['id'], 'pin' => '0000'])
            ->assertStatus(422)->assertJsonPath('message', 'محاولات كثيرة. الكاشير مقفل ٥ دقائق.');
        $this->withHeaders($device)->postJson('/api/v1/cashier-device/unlock', ['staff_id' => $noof['id'], 'pin' => '4817'])->assertStatus(429);
        $this->travel(6)->minutes();

        $staffToken = $this->withHeaders($device)->postJson('/api/v1/cashier-device/unlock', ['staff_id' => $noof['id'], 'pin' => '4817'])
            ->assertOk()->json('data.staff_token');
        $as = $device + ['X-Cashier-Staff' => $staffToken];
        $this->withHeaders($as)->postJson('/api/v1/cashier-device/cashier/shift/open', ['opening_cash' => 200])->assertCreated();
        $this->sell('/api/v1/cashier-device/cashier', $as, ['items' => [['product_id' => $this->product->id, 'quantity' => 1]]])
            ->assertCreated()->assertJsonPath('data.staff_name', 'نوف');
        $this->sell('/api/v1/cashier-device/cashier', $as, ['payment_method' => 'network'])->assertCreated();
        $closed = $this->withHeaders($as)->postJson('/api/v1/cashier-device/cashier/shift/close', ['counted_cash' => 220])
            ->assertOk()->json('data');
        $this->assertEquals(225, $closed['expected_cash']);
        $this->assertEquals(-5, $closed['difference']);
        $this->assertEquals(75, $closed['sales_total']);
        $this->assertSame(1, DB::table('in_app_notifications')->where('user_id', $this->owner->id)->where('title', 'تقرير وردية نوف')->count());

        // Locking the iPad ends her session there.
        $this->withHeaders($as)->postJson('/api/v1/cashier-device/lock')->assertOk();
        $this->withHeaders($as)->getJson('/api/v1/cashier-device/cashier')->assertStatus(423);

        // The owner can unlink the device from her phone.
        $this->flushHeaders();
        Sanctum::actingAs($this->owner);
        $deviceId = $this->getJson('/api/v1/app/family/cashier/staff')->json('data.devices.0.id');
        $this->deleteJson('/api/v1/app/family/cashier/devices/'.$deviceId)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($device)->getJson('/api/v1/cashier-device/me')->assertStatus(401);
    }

    public function test_owner_dashboard_limits_and_receipt_governance_show_the_cashier(): void
    {
        $this->pro();
        $noof = $this->addStaff('نوف');
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_owner' => true])->save();
        Sanctum::actingAs($admin);
        $this->patchJson('/api/admin/cashier-zad/settings', ['staff_limit' => 1, 'screens_limit' => 4])->assertOk()
            ->assertJsonPath('data.staff_limit', 1)->assertJsonPath('data.screens_limit', 4);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/app/family/cashier/staff', ['name' => 'ريم', 'role' => 'cashier', 'pin' => '1111'])->assertStatus(422);
        $employee = User::factory()->create();
        Sanctum::actingAs($employee);
        $this->postJson('/api/v1/app/staff/join', ['code' => $noof['invite']['code']])->assertOk();
        $base = '/api/v1/app/staff/'.$noof['id'].'/cashier';
        $this->postJson($base.'/shift/open', ['opening_cash' => 0])->assertCreated();
        $this->sell($base)->assertCreated();

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/sale-receipts?staff=نوف')->assertOk()->assertJsonPath('data.0.staff_name', 'نوف');
        $this->get('/api/admin/sale-receipts/export?format=csv')->assertOk();
    }

    public function test_employee_links_her_own_phone_with_the_invite_without_a_zad_login(): void
    {
        $this->pro();
        $noof = $this->addStaff('نوف', '2468');
        $reem = $this->addStaff('ريم', '1357');
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/cashier-device/join', ['code' => 'XXXXXX'])->assertStatus(422);
        $joined = $this->postJson('/api/v1/cashier-device/join', ['code' => 'ZADSTAFF:'.$noof['invite']['code']])
            ->assertCreated()->assertJsonPath('data.store_name', 'سوبو')->assertJsonPath('data.name', 'نوف')->json('data');
        $this->postJson('/api/v1/cashier-device/join', ['code' => $noof['invite']['code']])->assertStatus(422); // used

        $phone = ['X-Cashier-Device' => $joined['token']];
        $people = $this->withHeaders($phone)->getJson('/api/v1/cashier-device/me')->assertOk()
            ->assertJsonPath('data.personal', true)->json('data.people');
        $this->assertSame(['نوف'], array_column($people, 'name'));
        $this->withHeaders($phone)->postJson('/api/v1/cashier-device/unlock', ['staff_id' => $reem['id'], 'pin' => '1357'])->assertForbidden();
        $tok = $this->withHeaders($phone)->postJson('/api/v1/cashier-device/unlock', ['staff_id' => $noof['id'], 'pin' => '2468'])
            ->assertOk()->json('data.staff_token');
        $this->withHeaders($phone + ['X-Cashier-Staff' => $tok])->getJson('/api/v1/cashier-device/cashier')->assertOk()
            ->assertJsonPath('data.me.name', 'نوف');

        // Removing her unlinks her phone.
        $this->flushHeaders();
        Sanctum::actingAs($this->owner);
        $this->deleteJson('/api/v1/app/family/cashier/staff/'.$noof['id'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($phone)->getJson('/api/v1/cashier-device/me')->assertStatus(401);
    }
}
