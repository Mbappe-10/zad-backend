<?php

namespace Tests\Feature\Api;

use App\Models\ProductiveFamily;
use App\Models\SaleReceipt;
use App\Models\SaleReturn;
use App\Models\Store;
use App\Services\App\ZatcaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Real calls to ZATCA's public developer portal (sandbox). Runs only in the dedicated
 * CI job (ZATCA_LIVE=1): full onboarding with the sandbox OTP, then a simplified invoice,
 * its credit note and a standard (B2B) invoice, each signed and sent to ZATCA.
 */
#[Group('zatca-live')]
class ZatcaSandboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('ZATCA_LIVE')) {
            $this->markTestSkipped('ZATCA sandbox tests run only in the zatca CI job.');
        }
        config(['services.zatca.env' => 'sandbox']);
    }

    public function test_onboard_then_report_clear_and_credit_against_the_real_sandbox(): void
    {
        $family = ProductiveFamily::query()->create(['code' => 'PF-ZT', 'owner_name' => 'اختبار', 'phone' => '0551110000', 'status' => 'approved']);
        $store = Store::query()->create([
            'productive_family_id' => $family->id, 'name_ar' => 'سوبو', 'slug' => 'zt-store', 'status' => 'active', 'is_open' => true,
        ]);
        $store->forceFill(['vat_number' => '399999999900003', 'tax_trade_name' => 'مؤسسة سوبو'])->save();
        $z = app(ZatcaService::class);
        $z->saveProfile($store, [
            'seller_name' => 'مؤسسة سوبو التجارية', 'branch_name' => 'فرع الكورنيش', 'id_type' => 'CRN', 'id_number' => '1010010000',
            'street' => 'الكورنيش', 'building_number' => '2322', 'district' => 'الشاطئ', 'city' => 'جدة', 'postal_code' => '23412',
        ]);
        $unit = $z->onboard($store, '123345');
        fwrite(STDERR, "\nonboarded: {$unit->status} cert=".strlen((string) $unit->certificate)."\n");
        $this->assertTrue($unit->isActive());

        $receipt = SaleReceipt::query()->create([
            'public_token' => Str::random(40), 'sale_type' => 'quick', 'channel' => 'shop', 'store_id' => $store->id,
            'productive_family_id' => $family->id, 'store_name' => 'سوبو', 'payment_method' => 'cash',
            'subtotal' => 64, 'delivery_fee' => 0, 'discount' => 4, 'total' => 69, 'status' => 'paid', 'issued_at' => now(),
            'items' => [
                ['product_id' => null, 'name' => 'حبحبوه ١.٥ لتر', 'quantity' => 2, 'unit_price' => 25, 'total' => 50],
                ['product_id' => null, 'name' => 'سوبو خوخ', 'quantity' => 1, 'unit_price' => 14, 'total' => 14],
            ],
            'seller_trade_name' => 'مؤسسة سوبو', 'seller_vat_number' => '399999999900003', 'vat_rate' => 15, 'vat_amount' => 9,
        ]);
        $receipt->update(['number' => 'ZS-M-'.str_pad((string) $receipt->id, 6, '0', STR_PAD_LEFT)]);
        $doc = $z->issueForReceipt($receipt->refresh());
        $this->assertNotNull($doc);
        $doc = $z->submit($doc);
        fwrite(STDERR, "simplified: {$doc->status} ".json_encode([$doc->warnings, $doc->errors], JSON_UNESCAPED_UNICODE)."\n");
        $this->assertSame('reported', $doc->status);

        $return = SaleReturn::query()->create([
            'store_id' => $store->id, 'sale_receipt_id' => $receipt->id, 'number' => 'ZS-CN-000001', 'public_token' => Str::random(40),
            'items' => [['line' => 1, 'name' => 'سوبو خوخ', 'quantity' => 1, 'unit_price' => 14, 'total' => 14]],
            'subtotal' => 14, 'discount' => 0.88, 'vat_amount' => 1.97, 'total' => 15.07, 'reason' => 'استبدال منتج',
            'refund_method' => 'cash', 'issued_at' => now(),
        ]);
        $credit = $z->submit($z->issueForReturn($return));
        fwrite(STDERR, "credit: {$credit->status} ".json_encode([$credit->warnings, $credit->errors], JSON_UNESCAPED_UNICODE)."\n");
        $this->assertSame('reported', $credit->status);

        $b2b = SaleReceipt::query()->create([
            'public_token' => Str::random(40), 'sale_type' => 'quick', 'channel' => 'shop', 'store_id' => $store->id,
            'productive_family_id' => $family->id, 'store_name' => 'سوبو', 'payment_method' => 'network',
            'subtotal' => 100, 'delivery_fee' => 0, 'discount' => 0, 'total' => 115, 'status' => 'paid', 'issued_at' => now(),
            'items' => [['product_id' => null, 'name' => 'صينية حلا', 'quantity' => 4, 'unit_price' => 25, 'total' => 100]],
            'seller_trade_name' => 'مؤسسة سوبو', 'seller_vat_number' => '399999999900003', 'vat_rate' => 15, 'vat_amount' => 15,
            'customer_name' => 'شركة الريان للتموين', 'customer_vat_number' => '399999999800003',
            'customer_street' => 'صلاح الدين', 'customer_building' => '1111', 'customer_district' => 'المروج',
            'customer_city' => 'الرياض', 'customer_postal' => '12222',
        ]);
        $b2b->update(['number' => 'ZS-M-'.str_pad((string) $b2b->id, 6, '0', STR_PAD_LEFT)]);
        $std = $z->submit($z->issueForReceipt($b2b->refresh()));
        fwrite(STDERR, "standard: {$std->status} ".json_encode([$std->warnings, $std->errors], JSON_UNESCAPED_UNICODE)."\n");
        $this->assertSame('cleared', $std->status);
        $this->assertSame(3, (int) $unit->refresh()->icv);
    }

    /** The same journey through the app's own endpoints: profile → OTP → sales → return → scheduler. */
    public function test_full_cashier_journey_through_the_api_against_the_real_sandbox(): void
    {
        $plan = \App\Models\FamilyStorePlan::query()->where('code', 'cashier_pro')->first();
        $plan?->update(['capabilities' => array_replace((array) $plan->capabilities, ['trial_enabled' => false])]);
        $family = ProductiveFamily::query()->create(['code' => 'PF-ZJ', 'owner_name' => 'اختبار', 'phone' => '0551110001', 'status' => 'approved']);
        $store = Store::query()->create(['productive_family_id' => $family->id, 'name_ar' => 'سوبو', 'slug' => 'zj-store', 'status' => 'active', 'is_open' => true]);
        $owner = \App\Models\User::factory()->create();
        \App\Models\AppProfile::query()->create(['user_id' => $owner->id, 'productive_family_id' => $family->id, 'roles' => ['productive_family'], 'active_mode' => 'productive_family']);
        \App\Models\FamilyStoreGrant::query()->create(['productive_family_id' => $family->id, 'plan_code' => 'gold', 'source' => 'owner_grant', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30)]);
        \App\Models\FamilyCashierGrant::query()->create(['productive_family_id' => $family->id, 'source' => 'owner_grant', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30)]);
        $juice = \App\Models\Product::query()->forceCreate(['store_id' => $store->id, 'name_ar' => 'حبحبوه', 'price' => 25, 'status' => 'active', 'is_available' => true, 'preparation_minutes' => 10, 'package_size' => 'small']);
        \Laravel\Sanctum\Sanctum::actingAs($owner);
        $base = '/api/v1/app/family/cashier';

        $this->putJson($base.'/tax', ['trade_name' => 'مؤسسة سوبو', 'vat_number' => '399999999900003'])->assertOk();
        $this->putJson($base.'/zatca/profile', ['seller_name' => 'مؤسسة سوبو التجارية', 'id_type' => 'CRN', 'id_number' => '1010010000',
            'street' => 'الكورنيش', 'building_number' => '2322', 'district' => 'الشاطئ', 'city' => 'جدة', 'postal_code' => '23412'])->assertOk();
        $this->postJson($base.'/zatca/onboard', ['otp' => '123345'])->assertOk()->assertJsonPath('data.active', true);

        $sale = $this->postJson($base.'/sales', ['items' => [['product_id' => $juice->id, 'quantity' => 3]], 'payment_method' => 'network',
            'channel' => 'shop', 'discount' => 5])->assertCreated()->json('data');
        $this->assertEquals(80.5, $sale['total']);
        $b2b = $this->postJson($base.'/sales', ['items' => [['product_id' => $juice->id, 'quantity' => 2]], 'payment_method' => 'transfer', 'channel' => 'shop',
            'customer_name' => 'شركة الريان', 'customer_vat_number' => '399999999800003', 'customer_street' => 'صلاح الدين', 'customer_building' => '1111',
            'customer_district' => 'المروج', 'customer_city' => 'الرياض', 'customer_postal' => '12222'])->assertCreated()->json('data');
        fwrite(STDERR, "\napi b2b: {$b2b['zatca_status']}\n");
        $this->assertSame('cleared', $b2b['zatca_status']);
        $this->postJson($base.'/receipts/'.$sale['id'].'/returns', ['lines' => [['line' => 0, 'quantity' => 1]], 'reason' => 'خطأ في الطلب', 'refund_method' => 'network'])
            ->assertCreated()->assertJsonPath('data.is_credit_note', true);
        $this->postJson($base.'/receipts/'.$b2b['id'].'/returns', ['lines' => [['line' => 0, 'quantity' => 2]], 'reason' => 'إلغاء البيعة', 'refund_method' => 'transfer'])
            ->assertCreated()->assertJsonPath('data.zatca_status', 'cleared');

        $this->artisan('zad:zatca-submit')->assertSuccessful();
        $statuses = \App\Models\ZatcaDocument::query()->orderBy('icv')->get(['number', 'kind', 'invoice_type', 'status', 'warnings', 'errors']);
        fwrite(STDERR, json_encode($statuses, JSON_UNESCAPED_UNICODE)."\n");
        $this->assertSame(['reported', 'cleared', 'reported', 'cleared'], $statuses->pluck('status')->all());
        $this->get('/r/'.SaleReceipt::query()->whereKey($sale['id'])->value('public_token'))->assertOk();
    }
}
