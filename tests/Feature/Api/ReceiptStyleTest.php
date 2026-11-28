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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReceiptStyleTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private ProductiveFamily $family;
    private Product $juice;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = \App\Models\FamilyStorePlan::query()->where('code', 'cashier_pro')->first();
        $plan?->update(['capabilities' => array_replace((array) $plan->capabilities, ['trial_enabled' => false])]);
        $this->family = ProductiveFamily::query()->create([
            'code' => 'PF-RCPT', 'owner_name' => 'أبو سعد', 'phone' => '0551117777', 'status' => 'approved',
        ]);
        $this->store = Store::query()->create([
            'productive_family_id' => $this->family->id, 'name_ar' => 'سوبو', 'name_en' => 'SOBO',
            'slug' => 'sobo-rcpt', 'status' => 'active', 'is_open' => true,
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
        $this->juice = Product::query()->forceCreate([
            'store_id' => $this->store->id, 'name_ar' => 'حبحبوه', 'name_en' => 'Habhab Juice', 'price' => 25, 'status' => 'active',
            'is_available' => true, 'preparation_minutes' => 10, 'package_size' => 'small',
        ]);
        Sanctum::actingAs($owner);
    }

    private function pro(): FamilyCashierGrant
    {
        return FamilyCashierGrant::query()->create([
            'productive_family_id' => $this->family->id, 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30),
        ]);
    }

    private function sell(): SaleReceipt
    {
        $this->postJson('/api/v1/app/family/cashier/sales', [
            'items' => [['product_id' => $this->juice->id, 'quantity' => 2]], 'payment_method' => 'cash', 'channel' => 'shop',
        ])->assertCreated();

        return SaleReceipt::query()->latest('id')->firstOrFail();
    }

    public function test_classic_is_default_and_long_receipt_needs_pro(): void
    {
        $this->getJson('/api/v1/app/family/cashier/receipt-style')->assertOk()
            ->assertJsonPath('data.style', 'classic')->assertJsonPath('data.entitled', false)
            ->assertJsonPath('data.hide_branding_free', true);
        $this->putJson('/api/v1/app/family/cashier/receipt-style', ['style' => 'thermal'])->assertForbidden();
    }

    public function test_long_receipt_shows_store_logo_order_number_address_and_zad_card(): void
    {
        $this->pro();
        $this->putJson('/api/v1/app/family/cashier/receipt-style', [
            'style' => 'thermal', 'address' => 'جدة — الكورنيش الشمالي', 'social' => '@sobo.sa',
        ])->assertOk()->assertJsonPath('data.style', 'thermal')->assertJsonPath('data.address', 'جدة — الكورنيش الشمالي');
        // Another settings section saved later must not wipe the receipt style.
        $this->putJson('/api/v1/app/family/cashier/operations-settings', ['monthly_goal' => 500])->assertOk();
        $this->getJson('/api/v1/app/family/cashier/receipt-style')->assertJsonPath('data.style', 'thermal');

        $this->sell();
        $receipt = $this->sell();
        $html = $this->get('/r/'.$receipt->public_token)->assertOk()->getContent();
        $this->assertStringContainsString('Order# 2', $html);
        $this->assertStringContainsString('جدة — الكورنيش الشمالي', $html);
        $this->assertStringContainsString('Habhab Juice', $html);
        $this->assertStringContainsString('@sobo.sa', $html);
        $this->assertStringContainsString('بواسطة كاشير زاد سينك برو', $html);
        $this->assertStringContainsString('/stores/'.$this->store->id, $html);

        $pdf = $this->get('/r/'.$receipt->public_token.'/pdf')->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_owner_can_remove_the_zad_logo_for_free(): void
    {
        $this->pro();
        $this->putJson('/api/v1/app/family/cashier/receipt-style', ['style' => 'thermal', 'hide_zad_branding' => true])
            ->assertOk()->assertJsonPath('data.hide_zad_branding', true);
        $html = $this->get('/r/'.$this->sell()->public_token)->assertOk()->getContent();
        $this->assertStringContainsString('Order# 1', $html);
        $this->assertStringNotContainsString('بواسطة كاشير زاد سينك برو', $html);
        $this->assertStringNotContainsString('zad-receipt-logo', $html);
    }

    public function test_store_falls_back_to_classic_when_pro_ends(): void
    {
        $grant = $this->pro();
        $this->putJson('/api/v1/app/family/cashier/receipt-style', ['style' => 'thermal'])->assertOk();
        $receipt = $this->sell();
        $grant->update(['ends_at' => now()->subMinute()]);
        $html = $this->get('/r/'.$receipt->public_token)->assertOk()->getContent();
        $this->assertStringNotContainsString('Order#', $html);
    }
}
