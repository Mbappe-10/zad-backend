<?php

namespace Tests\Feature\Api;

use App\Models\AppProfile;
use App\Models\FamilyCashierGrant;
use App\Models\FamilyStoreGrant;
use App\Models\Product;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceLinkTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $owner;
    private ProductiveFamily $family;

    protected function setUp(): void
    {
        parent::setUp();
        $cashierPlan = \App\Models\FamilyStorePlan::query()->where('code', 'cashier_pro')->first();
        $cashierPlan?->update(['capabilities' => array_replace((array) $cashierPlan->capabilities, ['trial_enabled' => false])]);
        Storage::fake('r2_contracts');
        $this->family = ProductiveFamily::query()->create([
            'code' => 'PF-LINK', 'owner_name' => 'أم سارة', 'phone' => '0551118888', 'status' => 'approved',
        ]);
        $this->store = Store::query()->create([
            'productive_family_id' => $this->family->id, 'name_ar' => 'سوبو',
            'slug' => 'link-store', 'status' => 'active', 'is_open' => true,
        ]);
        $this->owner = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $this->owner->id, 'productive_family_id' => $this->family->id,
            'roles' => ['productive_family'], 'active_mode' => 'productive_family',
        ]);
        FamilyStoreGrant::query()->create([
            'productive_family_id' => $this->family->id, 'plan_code' => 'gold', 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30),
        ]);
        Sanctum::actingAs($this->owner);
    }

    private function product(string $name, float $price, array $extra = []): Product
    {
        return Product::query()->forceCreate(array_replace([
            'store_id' => $this->store->id, 'name_ar' => $name, 'price' => $price, 'status' => 'active',
            'is_available' => true, 'preparation_minutes' => 10, 'package_size' => 'small',
        ], $extra));
    }

    private function pro(): void
    {
        FamilyCashierGrant::query()->create([
            'productive_family_id' => $this->family->id, 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30),
        ]);
    }

    public function test_owner_shows_a_one_time_code_and_another_device_becomes_a_menu_screen(): void
    {
        $this->pro();
        $link = $this->postJson('/api/v1/app/family/cashier/link-codes', ['station' => 'menu', 'name' => 'شاشة المدخل'])
            ->assertCreated()->assertJsonPath('data.station_label', 'شاشة منيو')->json('data');
        $this->assertMatchesRegularExpression('/^ZADLINK:[A-Z0-9]{8}$/', $link['qr']);
        $this->getJson('/api/v1/app/family/cashier/link-codes/'.$link['code'])->assertJsonPath('data.state', 'pending');

        // The other device scans from حسابي, without the owner's account.
        $this->app['auth']->forgetGuards();
        $done = $this->postJson('/api/v1/devices/link', ['code' => $link['qr']])
            ->assertCreated()->assertJsonPath('data.station', 'menu')->assertJsonPath('data.device_name', 'شاشة المدخل')->json('data');
        $this->withHeaders(['X-Zad-Screen' => $done['token']])->getJson('/api/v1/screens/feed')->assertOk();
        $this->flushHeaders();

        // One time only.
        $this->postJson('/api/v1/devices/link', ['code' => $link['code']])->assertStatus(422);
        $this->postJson('/api/v1/devices/link', ['code' => 'ZZZZZZZZ'])->assertStatus(422);

        // The owner sees «تم الربط» and the screen in her list.
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/app/family/cashier/link-codes/'.$link['code'])->assertJsonPath('data.state', 'linked')
            ->assertJsonPath('data.device.name', 'شاشة المدخل');
        $this->getJson('/api/v1/app/family/cashier/screens')->assertJsonCount(1, 'data.screens');
    }

    public function test_inventory_station_opens_with_a_pin_and_free_stores_and_staff_cannot_make_codes(): void
    {
        // Without Cashier Pro the device link is refused when used, and the code stays usable after upgrading.
        $owner = app(\App\Services\App\CashierStaffService::class)->ownerRow($this->store);
        $this->postJson('/api/v1/app/family/cashier/link-codes', ['station' => 'inventory'])->assertStatus(422);
        $owner->forceFill(['pin_hash' => Hash::make('4321')])->save();
        $code = $this->postJson('/api/v1/app/family/cashier/link-codes', ['station' => 'inventory', 'name' => 'آيباد المخزن'])
            ->assertCreated()->json('data.code');
        $this->postJson('/api/v1/app/family/cashier/link-codes', ['station' => 'kitchen'])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/devices/link', ['code' => $code])->assertForbidden();
        $this->pro();
        $token = $this->postJson('/api/v1/devices/link', ['code' => $code])->assertCreated()
            ->assertJsonPath('data.station', 'inventory')->json('data.token');

        $this->withHeaders(['X-Cashier-Device' => $token]);
        $this->getJson('/api/v1/cashier-device/me')->assertOk()
            ->assertJsonPath('data.device.station', 'inventory')->assertJsonPath('data.device.station_label', 'المخزون');
        $this->postJson('/api/v1/cashier-device/unlock', ['staff_id' => $owner->id, 'pin' => '4321'])->assertOk();
        $this->flushHeaders();

        // A linked device cannot make link codes.
        $this->withHeaders(['X-Cashier-Device' => $token])->postJson('/api/v1/cashier-device/cashier/link-codes', ['station' => 'menu'])->assertNotFound();
    }
}
