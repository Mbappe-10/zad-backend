<?php

namespace Tests\Feature\Api;

use App\Models\AppProfile;
use App\Models\Order;
use App\Models\ProductiveFamily;
use App\Models\SaleReceipt;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaleReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $family = ProductiveFamily::query()->create([
            'code' => 'PF-RCPT1', 'owner_name' => 'أسرة الإيصالات', 'phone' => '0553330000', 'status' => 'approved',
        ]);
        $this->store = Store::query()->create([
            'productive_family_id' => $family->id, 'name_ar' => 'سوبو',
            'slug' => 'sobo', 'status' => 'active', 'is_open' => true,
        ]);
        $this->member = User::factory()->create();
        AppProfile::query()->create([
            'user_id' => $this->member->id, 'productive_family_id' => $family->id,
            'roles' => ['productive_family'], 'active_mode' => 'productive_family',
        ]);
    }

    private function unpaidOrder(string $number, float $total = 82): Order
    {
        $id = DB::table('orders')->insertGetId([
            'number' => $number, 'store_id' => $this->store->id, 'status' => 'pending', 'payment_status' => 'unpaid',
            'subtotal' => $total, 'total' => $total, 'contact_phone' => '0551234812',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $id, 'product_name' => 'حبحبوه', 'quantity' => 2, 'unit_price' => $total / 2, 'total' => $total,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Order::query()->findOrFail($id);
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->forceFill(['is_platform_owner' => true])->save();

        return $owner;
    }

    public function test_paying_an_order_issues_one_receipt_and_a_refund_marks_it(): void
    {
        $order = $this->unpaidOrder('ORD-1');
        $this->assertSame(0, SaleReceipt::query()->count());

        $order->update(['payment_status' => Order::PAYMENT_PAID]);
        $receipt = SaleReceipt::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('ZS-R-'.str_pad((string) $receipt->id, 6, '0', STR_PAD_LEFT), $receipt->number);
        $this->assertSame('platform', $receipt->sale_type);
        $this->assertSame('حبحبوه', $receipt->items[0]['name']);
        $this->assertSame(40, strlen($receipt->public_token));

        $order->update(['status' => 'preparing']);
        $this->assertSame(1, SaleReceipt::query()->count());

        $order->update(['payment_status' => Order::PAYMENT_REFUNDED]);
        $this->assertSame('refunded', $receipt->fresh()->status);
    }

    public function test_public_page_and_pdf_open_by_token_and_hide_the_phone(): void
    {
        $order = $this->unpaidOrder('ORD-2');
        $order->update(['payment_status' => Order::PAYMENT_PAID]);
        $receipt = SaleReceipt::query()->firstOrFail();

        $this->get('/r/'.$receipt->public_token)->assertOk()
            ->assertSee($receipt->number)->assertSee('سوبو')->assertDontSee('0551234812');
        $this->assertNotNull($receipt->fresh()->opened_at);
        $this->get('/r/'.$receipt->public_token.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/r/'.str_repeat('a', 40))->assertNotFound();
    }

    public function test_family_sees_only_its_own_receipts(): void
    {
        $this->unpaidOrder('ORD-3')->update(['payment_status' => Order::PAYMENT_PAID]);
        $receipt = SaleReceipt::query()->firstOrFail();
        Sanctum::actingAs($this->member);
        $this->getJson('/api/v1/app/family/receipts')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.number', $receipt->number)
            ->assertJsonPath('data.0.customer', '05•• ••• 812')->assertJsonMissingPath('data.0.public_token');
        $this->get('/api/v1/app/family/receipts/'.$receipt->id.'/pdf')->assertOk();

        $other = ProductiveFamily::query()->create(['code' => 'PF-RCPT2', 'owner_name' => 'أخرى', 'phone' => '0553339999', 'status' => 'approved']);
        Store::query()->create(['productive_family_id' => $other->id, 'name_ar' => 'أخرى', 'slug' => 'other', 'status' => 'active', 'is_open' => true]);
        $stranger = User::factory()->create();
        AppProfile::query()->create(['user_id' => $stranger->id, 'productive_family_id' => $other->id, 'roles' => ['productive_family'], 'active_mode' => 'productive_family']);
        Sanctum::actingAs($stranger);
        $this->get('/api/v1/app/family/receipts/'.$receipt->id.'/pdf')->assertNotFound();
        $this->getJson('/api/v1/app/family/receipts')->assertJsonPath('meta.total', 0);
    }

    public function test_admin_searches_exports_and_zips_and_old_paid_orders_are_backfilled(): void
    {
        $this->unpaidOrder('ORD-10')->update(['payment_status' => Order::PAYMENT_PAID]);
        DB::table('orders')->insert([
            'number' => 'ORD-OLD', 'store_id' => $this->store->id, 'status' => 'delivered', 'payment_status' => 'paid',
            'subtotal' => 50, 'total' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->unpaidOrder('ORD-UNPAID');

        Sanctum::actingAs($this->member);
        $this->getJson('/api/admin/sale-receipts')->assertForbidden();

        Sanctum::actingAs($this->owner());
        $this->getJson('/api/admin/sale-receipts')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.today.count', 2)
            ->assertJsonPath('summary.today.total', 132);
        $this->getJson('/api/admin/sale-receipts?q=ORD-OLD')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.order_number', 'ORD-OLD');
        $this->getJson('/api/admin/sale-receipts?q=1234812')->assertJsonPath('meta.total', 1);

        $id = SaleReceipt::query()->value('id');
        $this->get('/api/admin/sale-receipts/'.$id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/api/admin/sale-receipts/export?format=xlsx')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->get('/api/admin/sale-receipts/export?format=doc')->assertOk()->assertSee('تقرير مبيعات زاد سينك', false);
        $csv = $this->get('/api/admin/sale-receipts/export?format=csv')->assertOk()->getContent();
        $this->assertStringContainsString('ZS-R-', $csv);
        $this->get('/api/admin/sale-receipts/export?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $zip = $this->get('/api/admin/sale-receipts/zip')->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringStartsWith('PK', $zip->getContent());
    }
}
