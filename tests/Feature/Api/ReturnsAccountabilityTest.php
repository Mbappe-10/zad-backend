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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Returns made by an employee are traced to her: the owner's log, and her shift's cash. */
class ReturnsAccountabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_return_comes_off_her_shift_cash_and_shows_in_the_owner_log(): void
    {
        $plan = \App\Models\FamilyStorePlan::query()->where('code', 'cashier_pro')->first();
        $plan?->update(['capabilities' => array_replace((array) $plan->capabilities, ['trial_enabled' => false])]);
        $family = ProductiveFamily::query()->create(['code' => 'PF-RA', 'owner_name' => 'أبو سعد', 'phone' => '0551113333', 'status' => 'approved']);
        $store = Store::query()->create(['productive_family_id' => $family->id, 'name_ar' => 'سوبو', 'slug' => 'ra-store', 'status' => 'active', 'is_open' => true]);
        $owner = User::factory()->create();
        AppProfile::query()->create(['user_id' => $owner->id, 'productive_family_id' => $family->id, 'roles' => ['productive_family'], 'active_mode' => 'productive_family']);
        FamilyStoreGrant::query()->create(['productive_family_id' => $family->id, 'plan_code' => 'gold', 'source' => 'owner_grant', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30)]);
        FamilyCashierGrant::query()->create(['productive_family_id' => $family->id, 'source' => 'owner_grant', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30)]);
        $juice = Product::query()->forceCreate(['store_id' => $store->id, 'name_ar' => 'حبحبوه', 'price' => 25, 'status' => 'active', 'is_available' => true, 'preparation_minutes' => 10, 'package_size' => 'small']);
        Sanctum::actingAs($owner);

        $noof = $this->postJson('/api/v1/app/family/cashier/staff', ['name' => 'نوف', 'role' => 'supervisor', 'pin' => '4817'])->assertCreated()->json('data');
        $ownerRow = collect($this->getJson('/api/v1/app/family/cashier/staff')->json('data.staff'))->firstWhere('role', 'owner');
        $this->patchJson('/api/v1/app/family/cashier/staff/'.$ownerRow['id'], ['pin' => '9999'])->assertOk();
        $token = $this->postJson('/api/v1/app/family/cashier/devices', ['kind' => 'cashier', 'name' => 'آيباد المحل'])->assertCreated()->json('data.token');

        $this->app['auth']->forgetGuards();
        $device = ['X-Cashier-Device' => $token];
        $staffToken = $this->withHeaders($device)->postJson('/api/v1/cashier-device/unlock', ['staff_id' => $noof['id'], 'pin' => '4817'])
            ->assertOk()->json('data.staff_token');
        $as = $device + ['X-Cashier-Staff' => $staffToken];
        $base = '/api/v1/cashier-device/cashier';
        $this->withHeaders($as)->postJson($base.'/shift/open', ['opening_cash' => 100])->assertCreated();
        $sale = $this->withHeaders($as)->postJson($base.'/sales', ['items' => [['product_id' => $juice->id, 'quantity' => 2]], 'payment_method' => 'cash', 'channel' => 'shop'])
            ->assertCreated()->json('data');
        // Return by invoice number, right from the cashier.
        $found = $this->withHeaders($as)->getJson($base.'/recent-sales?number='.$sale['number'])->assertOk()->json('data');
        $this->assertSame($sale['id'], $found[0]['id']);
        $this->withHeaders($as)->postJson($base.'/receipts/'.$sale['id'].'/returns', ['lines' => [['line' => 0, 'quantity' => 1]], 'reason' => 'خطأ في الطلب', 'refund_method' => 'cash'])
            ->assertCreated()->assertJsonPath('data.staff_name', 'نوف')->assertJsonPath('data.receipt_number', $sale['number']);
        $closed = $this->withHeaders($as)->postJson($base.'/shift/close', ['counted_cash' => 125])->assertOk()->json('data');
        $this->assertEquals(125, $closed['expected_cash']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($owner);
        $log = $this->getJson('/api/v1/app/family/cashier/returns-report')->assertOk()->json('data');
        $this->assertEquals(25, $log['total']);
        $this->assertSame('نوف', $log['by_staff'][0]['staff_name']);
        $this->assertEquals(25, $log['by_staff'][0]['cash']);
        $this->assertSame('خطأ في الطلب', $log['recent'][0]['reason']);
    }
}
