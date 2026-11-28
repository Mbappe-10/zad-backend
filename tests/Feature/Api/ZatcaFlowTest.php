<?php

namespace Tests\Feature\Api;

use App\Models\AppProfile;
use App\Models\FamilyCashierGrant;
use App\Models\FamilyStoreGrant;
use App\Models\Product;
use App\Models\ProductiveFamily;
use App\Models\SaleReceipt;
use App\Models\Store;
use App\Models\User;
use App\Models\ZatcaDocument;
use App\Models\ZatcaEgsUnit;
use App\Services\App\ZatcaService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Returns / credit notes, and the Fatoora flow with ZATCA's API mocked (the live sandbox job covers the real API). */
class ZatcaFlowTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private ProductiveFamily $family;
    private Product $juice;
    private MockHandler $http;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = \App\Models\FamilyStorePlan::query()->where('code', 'cashier_pro')->first();
        $plan?->update(['capabilities' => array_replace((array) $plan->capabilities, ['trial_enabled' => false])]);
        $this->family = ProductiveFamily::query()->create(['code' => 'PF-ZF', 'owner_name' => 'أبو سعد', 'phone' => '0551112222', 'status' => 'approved']);
        $this->store = Store::query()->create([
            'productive_family_id' => $this->family->id, 'name_ar' => 'سوبو', 'slug' => 'zf-store', 'status' => 'active', 'is_open' => true,
        ]);
        $owner = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $owner->id, 'productive_family_id' => $this->family->id,
            'roles' => ['productive_family'], 'active_mode' => 'productive_family',
        ]);
        FamilyStoreGrant::query()->create([
            'productive_family_id' => $this->family->id, 'plan_code' => 'gold', 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30),
        ]);
        FamilyCashierGrant::query()->create([
            'productive_family_id' => $this->family->id, 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30),
        ]);
        $this->juice = Product::query()->forceCreate([
            'store_id' => $this->store->id, 'name_ar' => 'حبحبوه', 'price' => 25, 'status' => 'active',
            'is_available' => true, 'preparation_minutes' => 10, 'package_size' => 'small',
        ]);
        $this->http = new MockHandler();
        $this->app->instance('zatca.http', new Client(['handler' => HandlerStack::create($this->http)]));
        Sanctum::actingAs($owner);
    }

    private function sell(int $qty = 2, array $extra = [])
    {
        return $this->postJson('/api/v1/app/family/cashier/sales', array_replace([
            'items' => [['product_id' => $this->juice->id, 'quantity' => $qty]], 'payment_method' => 'cash', 'channel' => 'shop',
        ], $extra));
    }

    /** A real secp256k1 key with a self-signed certificate, standing in for the ZATCA-issued one. */
    private function linkStore(): ZatcaEgsUnit
    {
        $this->store->forceFill(['vat_number' => '399999999900003', 'tax_trade_name' => 'مؤسسة سوبو'])->save();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);
        $csr = openssl_csr_new(['commonName' => 'test', 'organizationName' => 'Zad', 'countryName' => 'SA'], $key, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($key, $keyPem);
        $cert = trim(preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $certPem));

        return ZatcaEgsUnit::query()->create([
            'store_id' => $this->store->id, 'environment' => 'sandbox', 'status' => 'active', 'device_uuid' => (string) Str::uuid(),
            'vat_number' => '399999999900003', 'seller_name' => 'مؤسسة سوبو', 'id_type' => 'CRN', 'id_number' => '1010010000',
            'street' => 'الكورنيش', 'building_number' => '2322', 'district' => 'الشاطئ', 'city' => 'جدة', 'postal_code' => '23412',
            'private_key' => $keyPem, 'certificate' => $cert, 'secret' => 'secret', 'icv' => 0, 'last_hash' => ZatcaService::INITIAL_PIH,
        ]);
    }

    public function test_returns_issue_a_credit_note_and_come_off_the_day(): void
    {
        $id = $this->sell(3)->assertCreated()->json('data.id');
        $lines = $this->getJson("/api/v1/app/family/cashier/receipts/{$id}/returnable")->assertOk()->json('data.lines');
        $this->assertSame(3, $lines[0]['remaining']);

        $this->postJson("/api/v1/app/family/cashier/receipts/{$id}/returns", [
            'lines' => [['line' => 0, 'quantity' => 4]], 'reason' => 'خطأ', 'refund_method' => 'cash',
        ])->assertStatus(422);
        $ret = $this->postJson("/api/v1/app/family/cashier/receipts/{$id}/returns", [
            'lines' => [['line' => 0, 'quantity' => 1]], 'reason' => 'استبدال', 'refund_method' => 'cash',
        ])->assertCreated()->json('data');
        $this->assertEquals(25, $ret['total']);
        $this->assertFalse($ret['is_credit_note']);

        $this->assertSame(2, $this->getJson("/api/v1/app/family/cashier/receipts/{$id}/returnable")->json('data.lines.0.remaining'));
        $day = $this->getJson('/api/v1/app/family/cashier/day')->assertOk()->json('data');
        $this->assertEquals(50, $day['direct_total']);
        $this->assertEquals(25, $day['returns_total']);
        $this->get('/c/'.\App\Models\SaleReturn::query()->value('public_token'))->assertOk()->assertSee('ZS-CN-');
        $this->getJson('/api/v1/app/family/cashier/recent-sales')->assertOk()->assertJsonPath('data.0.returned_total', 25);
    }

    public function test_zatca_is_optional_and_needs_pro(): void
    {
        $this->getJson('/api/v1/app/family/cashier/zatca')->assertOk()
            ->assertJsonPath('data.status', 'none')->assertJsonPath('data.active', false)->assertJsonPath('data.entitled', true);
        // Not linked: a sale stays a normal receipt with no e-invoice.
        $this->sell()->assertCreated()->assertJsonPath('data.tax', null);
        $this->assertSame(0, ZatcaDocument::query()->count());
        $this->putJson('/api/v1/app/family/cashier/zatca/profile', ['seller_name' => 'x', 'id_type' => 'CRN', 'id_number' => '1',
            'street' => 'a', 'building_number' => '12', 'district' => 'b', 'city' => 'c', 'postal_code' => '1'])->assertStatus(422);
    }

    public function test_linked_store_signs_chains_reports_clears_and_credits(): void
    {
        $unit = $this->linkStore();
        $this->http->append(new Response(200, [], json_encode(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS', 'warningMessages' => [], 'errorMessages' => []]])));
        $this->http->append(new Response(200, [], json_encode(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS', 'warningMessages' => [], 'errorMessages' => []]])));

        // Every sale becomes a VAT e-invoice, even without the VAT box.
        $first = $this->sell()->assertCreated()->json('data');
        $this->assertSame('فاتورة ضريبية مبسطة', $first['tax']['title']);
        $d1 = ZatcaDocument::query()->where('sale_receipt_id', $first['id'])->firstOrFail();
        $this->assertSame(1, (int) $d1->icv);
        $this->assertSame(ZatcaService::INITIAL_PIH, $d1->previous_hash);
        $this->assertStringContainsString('<cbc:UUID>'.$d1->uuid.'</cbc:UUID>', $d1->signed_xml);
        $this->assertStringContainsString('ds:SignatureValue', $d1->signed_xml);
        // The receipt now carries the phase-2 QR (with hash and signature).
        $this->assertSame($d1->qr, $first['tax']['qr']);

        $second = $this->sell(1)->assertCreated()->json('data');
        $d2 = ZatcaDocument::query()->where('sale_receipt_id', $second['id'])->firstOrFail();
        $this->assertSame(2, (int) $d2->icv);
        $this->assertSame($d1->invoice_hash, $d2->previous_hash);

        $this->artisan('zad:zatca-submit')->assertSuccessful();
        $this->assertSame(['reported', 'reported'], ZatcaDocument::query()->orderBy('icv')->pluck('status')->all());

        // B2B needs the buyer's national address, then ZATCA clears it before it is handed over.
        $this->sell(1, ['customer_name' => 'شركة الريان', 'customer_vat_number' => '399999999800003'])->assertStatus(422);
        $this->http->append(function ($request) {
            $body = json_decode((string) $request->getBody(), true);

            return new Response(200, [], json_encode(['clearanceStatus' => 'CLEARED', 'clearedInvoice' => $body['invoice'],
                'validationResults' => ['status' => 'PASS', 'warningMessages' => [], 'errorMessages' => []]]));
        });
        $b2b = $this->sell(4, ['customer_name' => 'شركة الريان', 'customer_vat_number' => '399999999800003', 'customer_street' => 'صلاح الدين',
            'customer_building' => '1111', 'customer_district' => 'المروج', 'customer_city' => 'الرياض', 'customer_postal' => '12222'])->assertCreated()->json('data');
        $this->assertSame('فاتورة ضريبية', $b2b['tax']['title']);
        $this->assertSame('cleared', $b2b['zatca_status']);

        // A return is a credit note in the same chain, referring to the invoice.
        $this->http->append(new Response(200, [], json_encode(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS', 'warningMessages' => [], 'errorMessages' => []]])));
        $credit = $this->postJson("/api/v1/app/family/cashier/receipts/{$first['id']}/returns", [
            'lines' => [['line' => 0, 'quantity' => 1]], 'reason' => 'استبدال', 'refund_method' => 'cash',
        ])->assertCreated()->json('data');
        $this->assertTrue($credit['is_credit_note']);
        $this->assertEquals(28.75, $credit['total']);
        $cn = ZatcaDocument::query()->where('kind', 'credit')->firstOrFail();
        $this->assertSame(4, (int) $cn->icv);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">381</cbc:InvoiceTypeCode>', $cn->signed_xml);
        $this->assertStringContainsString($first['number'], $cn->signed_xml);
        $this->artisan('zad:zatca-submit')->assertSuccessful();
        $this->assertSame('reported', $cn->refresh()->status);
        $this->assertSame(4, (int) $unit->refresh()->icv);

        // The VAT number can't change while linked.
        $this->putJson('/api/v1/app/family/cashier/tax', ['trade_name' => 'x', 'vat_number' => null])->assertStatus(422);
        $this->getJson('/api/v1/app/family/cashier/zatca')->assertJsonPath('data.active', true)->assertJsonPath('data.invoices_count', 4);
    }
}
