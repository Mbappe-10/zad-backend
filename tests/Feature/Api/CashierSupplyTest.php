<?php

namespace Tests\Feature\Api;

use App\Models\AppProfile;
use App\Models\CashierDevice;
use App\Models\CashierMaterial;
use App\Models\CashierPurchase;
use App\Models\CashierTransfer;
use App\Models\FamilyCashierGrant;
use App\Models\FamilyStoreGrant;
use App\Models\Product;
use App\Models\ProductiveFamily;
use App\Models\SaleReceipt;
use App\Models\Store;
use App\Models\User;
use App\Services\App\CashierSupplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashierSupplyTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/app/family/cashier';

    private ProductiveFamily $family;
    private Store $store;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = \App\Models\FamilyStorePlan::query()->where('code', 'cashier_pro')->first();
        $plan?->update(['capabilities' => array_replace((array) $plan->capabilities, ['trial_enabled' => false])]);
        $this->family = ProductiveFamily::query()->create([
            'code' => 'PF-SUP1', 'owner_name' => 'أسرة الموردين', 'phone' => '0551119999', 'status' => 'approved',
        ]);
        $this->store = Store::query()->create([
            'productive_family_id' => $this->family->id, 'name_ar' => 'سوبو', 'slug' => 'sup-store', 'status' => 'active', 'is_open' => true,
        ]);
        $this->owner = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $this->owner->id, 'productive_family_id' => $this->family->id,
            'roles' => ['productive_family'], 'active_mode' => 'productive_family',
        ]);
        FamilyStoreGrant::query()->create([
            'productive_family_id' => $this->family->id, 'plan_code' => 'gold', 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(10),
        ]);
        Sanctum::actingAs($this->owner);
    }

    private function pro(): void
    {
        FamilyCashierGrant::query()->create(['productive_family_id' => $this->family->id, 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(5)]);
    }

    private function product(string $name, int $stock): Product
    {
        return Product::query()->forceCreate([
            'store_id' => $this->store->id, 'name_ar' => $name, 'price' => 10, 'status' => 'active', 'is_available' => true,
            'preparation_minutes' => 10, 'package_size' => 'small', 'track_stock' => true, 'stock_quantity' => $stock,
        ]);
    }

    public function test_purchase_invoice_puts_stock_in_averages_cost_and_payments_settle_oldest_first(): void
    {
        $this->postJson(self::API.'/suppliers', ['name' => 'مصنع الكاسات'])->assertForbidden();
        $this->pro();

        $supplier = $this->postJson(self::API.'/suppliers', ['name' => 'مصنع الكاسات', 'phone' => '0555000111', 'payment_days' => 7])
            ->assertCreated()->json('data.id');
        $cups = CashierMaterial::query()->create(['store_id' => $this->store->id, 'name' => 'كاسات', 'unit' => 'حبة', 'quantity' => 100, 'unit_cost' => 0.2]);
        $water = $this->product('ماء', 0);

        // A draft order changes nothing; receiving it puts the goods in.
        $po = $this->postJson(self::API.'/purchases', ['supplier_id' => $supplier, 'vat_mode' => 'add', 'items' => [
            ['material_id' => $cups->id, 'quantity' => 100, 'unit_cost' => 0.4],
            ['product_id' => $water->id, 'quantity' => 24, 'unit_cost' => 1],
        ]])->assertCreated()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.subtotal', 64.0)->assertJsonPath('data.vat', 9.6)->assertJsonPath('data.total', 73.6)->json('data');
        $this->assertEqualsWithDelta(100, $cups->fresh()->quantity, 0.001);

        $received = $this->postJson(self::API."/purchases/{$po['id']}/receive")->assertOk()
            ->assertJsonPath('data.status', 'received')->assertJsonPath('data.payment_status', 'unpaid')->json('data');
        $this->assertSame(now('Asia/Riyadh')->addDays(7)->toDateString(), $received['due_date']);
        $this->assertEqualsWithDelta(200, $cups->fresh()->quantity, 0.001);
        $this->assertEqualsWithDelta(0.3, $cups->fresh()->unit_cost, 0.001); // (100×0.2 + 100×0.4) / 200
        $this->assertSame($supplier, $cups->fresh()->supplier_id);
        $this->assertSame(24, $water->fresh()->stock_quantity);
        $this->postJson(self::API."/purchases/{$po['id']}/receive")->assertStatus(422);

        // A second invoice, received and partly paid on the spot.
        $this->postJson(self::API.'/purchases', ['supplier_id' => $supplier, 'receive' => true, 'paid_now' => 10, 'method' => 'cash',
            'items' => [['material_id' => $cups->id, 'quantity' => 50, 'unit_cost' => 0.4]]])
            ->assertCreated()->assertJsonPath('data.payment_status', 'partial')->assertJsonPath('data.remaining', 10.0);

        // Paying the supplier settles the oldest invoice first; more than owed is refused.
        $this->postJson(self::API."/suppliers/{$supplier}/payments", ['amount' => 100, 'method' => 'transfer'])->assertStatus(422);
        $this->postJson(self::API."/suppliers/{$supplier}/payments", ['amount' => 80, 'method' => 'transfer'])->assertCreated()
            ->assertJsonPath('data.applied.0.number', 'PO-0001')->assertJsonPath('data.applied.0.amount', 73.6)
            ->assertJsonPath('data.balance', 3.6);
        $statement = $this->getJson(self::API."/suppliers/{$supplier}/statement")->assertOk()->json('data');
        $this->assertEqualsWithDelta(93.6, $statement['purchased'], 0.001);
        $this->assertEqualsWithDelta(3.6, $statement['balance'], 0.001);
        $this->getJson(self::API.'/supply')->assertOk()->assertJsonPath('data.totals.owed', 3.6)
            ->assertJsonPath('data.branches.0.is_main', true);

        // A supplier with invoices is stopped, not deleted.
        $this->deleteJson(self::API."/suppliers/{$supplier}")->assertOk();
        $this->assertFalse((bool) DB::table('cashier_suppliers')->find($supplier)->active);
    }

    public function test_branches_transfers_sales_par_levels_and_daily_automation(): void
    {
        $this->pro();
        $juice = $this->product('حبحبوه', 50);
        $sugar = CashierMaterial::query()->create(['store_id' => $this->store->id, 'name' => 'سكر', 'unit' => 'كيس', 'quantity' => 3, 'unit_cost' => 5, 'low_alert' => 4]);
        $supplier = $this->postJson(self::API.'/suppliers', ['name' => 'تموينات الحي'])->assertCreated()->json('data.id');
        $this->patchJson(self::API."/materials/{$sugar->id}/supply", ['supplier_id' => $supplier, 'reorder_quantity' => 10])->assertOk();
        // Setting the supplier of a low material drafts its reorder right away, once.
        $auto = CashierPurchase::query()->where('supplier_id', $supplier)->where('auto', true)->get();
        $this->assertCount(1, $auto);
        $this->assertEqualsWithDelta(10, $auto->first()->items()->first()->quantity, 0.001);
        $this->postJson(self::API.'/reorder')->assertOk()->assertJsonPath('message', 'ما فيه مواد تحتاج طلب جديد.');

        $main = $this->getJson(self::API.'/supply')->json('data.branches.0.id');
        $stall = $this->postJson(self::API.'/branches', ['name' => 'بسطة الكورنيش', 'kind' => 'stall'])->assertCreated()->json('data.id');
        $key = CashierSupplyService::productKey($juice->id);

        $this->postJson(self::API.'/transfers', ['from_branch_id' => $main, 'to_branch_id' => $stall,
            'items' => [['key' => $key, 'quantity' => 60]]])->assertStatus(422);
        $this->postJson(self::API.'/transfers', ['from_branch_id' => $main, 'to_branch_id' => $stall,
            'items' => [['key' => $key, 'quantity' => 12]]])->assertCreated()->assertJsonPath('data.status', 'done');
        $stallItems = collect($this->getJson(self::API."/branches/{$stall}/stock")->assertOk()->json('data.items'))->keyBy('key');
        $mainItems = collect($this->getJson(self::API."/branches/{$main}/stock")->json('data.items'))->keyBy('key');
        $this->assertEqualsWithDelta(12, $stallItems[$key]['quantity'], 0.001);
        $this->assertEqualsWithDelta(38, $mainItems[$key]['quantity'], 0.001);
        $this->assertSame(50, $juice->fresh()->stock_quantity); // a transfer never changes the total

        // A sale on the stall's device takes from the stall.
        $device = CashierDevice::query()->create(['store_id' => $this->store->id, 'kind' => 'cashier', 'name' => 'جهاز البسطة', 'token_hash' => hash('sha256', 'stall')]);
        $this->patchJson(self::API."/devices/{$device->id}/branch", ['branch_id' => $stall])->assertOk();
        $this->putJson(self::API."/branches/{$stall}/par-levels", ['levels' => [['key' => $key, 'par_level' => 10]]])->assertOk();
        $receipt = (new SaleReceipt)->forceFill(['store_id' => $this->store->id, 'cashier_device_id' => $device->id,
            'items' => [['product_id' => $juice->id, 'quantity' => 5]]]);
        app(CashierSupplyService::class)->onSale($receipt);
        $this->assertEqualsWithDelta(7, collect($this->getJson(self::API."/branches/{$stall}/stock")->json('data.items'))->firstWhere('key', $key)['quantity'], 0.001);

        // Under its par level: a refill from the main branch is drafted and the owner is told.
        $refill = CashierTransfer::query()->where('to_branch_id', $stall)->where('auto', true)->where('status', 'draft')->firstOrFail();
        $this->assertEqualsWithDelta(3, $refill->items[0]['quantity'], 0.001);
        $this->assertTrue(DB::table('in_app_notifications')->where('user_id', $this->owner->id)->where('title', 'تعبئة بسطة الكورنيش')->exists());
        app(CashierSupplyService::class)->onSale($receipt->forceFill(['items' => [['product_id' => $juice->id, 'quantity' => 1]]]));
        $this->assertSame(1, CashierTransfer::query()->where('to_branch_id', $stall)->where('status', 'draft')->count());
        $this->postJson(self::API."/transfers/{$refill->id}/complete")->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertEqualsWithDelta(9, collect($this->getJson(self::API."/branches/{$stall}/stock")->json('data.items'))->firstWhere('key', $key)['quantity'], 0.001);

        // A branch holding stock cannot be deleted.
        $this->deleteJson(self::API."/branches/{$stall}")->assertStatus(422);

        // The daily run reminds about a supplier invoice that is due.
        $po = CashierPurchase::query()->where('supplier_id', $supplier)->firstOrFail();
        $this->postJson(self::API."/purchases/{$po->id}/receive")->assertOk();
        $po->refresh()->forceFill(['due_date' => now('Asia/Riyadh')->subDay()->toDateString()])->save();
        $this->artisan('zad:cashier-supply-daily')->assertSuccessful();
        $this->assertTrue(DB::table('in_app_notifications')->where('user_id', $this->owner->id)->where('title', 'فاتورة مورد متأخرة')->exists());
        $this->getJson(self::API.'/purchases?status=unpaid')->assertOk()->assertJsonPath('data.0.overdue', true);
    }
}
