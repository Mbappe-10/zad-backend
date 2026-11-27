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
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashierScreensTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $owner;
    private ProductiveFamily $family;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2_contracts');
        $this->family = ProductiveFamily::query()->create([
            'code' => 'PF-SCREEN', 'owner_name' => 'أم سارة', 'phone' => '0551118888', 'status' => 'approved',
        ]);
        $this->store = Store::query()->create([
            'productive_family_id' => $this->family->id, 'name_ar' => 'سوبو',
            'slug' => 'screen-store', 'status' => 'active', 'is_open' => true,
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

    public function test_screen_pairs_with_a_code_shows_the_designed_menu_and_the_receipt_after_payment(): void
    {
        $a = $this->product('حبحبوه', 25);
        $b = $this->product('كركديه', 18, ['track_stock' => true, 'stock_quantity' => 0]);
        $hidden = $this->product('سري', 5);

        // Basic gold cannot pair screens.
        $this->getJson('/api/v1/app/family/cashier/screens')->assertOk()->assertJsonPath('data.enabled', false);
        $this->postJson('/api/v1/app/family/cashier/screens/claim', ['code' => '123456', 'kind' => 'menu', 'name' => 'x'])->assertForbidden();
        FamilyCashierGrant::query()->create([
            'productive_family_id' => $this->family->id, 'source' => 'owner_grant',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30),
        ]);

        // The TV asks for a code (no login).
        $this->app['auth']->forgetGuards();
        $pair = $this->postJson('/api/v1/screens/pair')->assertOk()->json('data');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pair['code']);
        $this->getJson('/api/v1/screens/pair/'.$pair['poll'])->assertOk()->assertJsonPath('data.token', null);

        // The owner claims it from her phone.
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/app/family/cashier/screens/claim', ['code' => '000000', 'kind' => 'menu', 'name' => 'x'])->assertStatus(422);
        $this->postJson('/api/v1/app/family/cashier/screens/claim', ['code' => $pair['code'], 'kind' => 'menu', 'name' => 'منيو الصالة'])
            ->assertCreated()->assertJsonPath('data.settings.show_receipts', true);

        $this->app['auth']->forgetGuards();
        $token = $this->getJson('/api/v1/screens/pair/'.$pair['poll'])->assertOk()->json('data.token');
        $this->assertNotEmpty($token);
        $this->getJson('/api/v1/screens/pair/'.$pair['poll'])->assertStatus(410); // once only

        // The owner designs the menu.
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/app/family/cashier/menu', [
            'title' => 'سوبو | SOBO', 'theme' => 'night', 'accent' => '#C99A2E', 'layout' => 'list',
            'hidden_product_ids' => [$hidden->id], 'show_offer' => true, 'offer_text' => '٢ حبحبوه بـ ٤٥', 'offer_product_id' => $a->id,
            'ticker' => 'طلبات المناسبات قبل يومين',
        ])->assertOk();
        $this->putJson('/api/v1/app/family/cashier/menu', ['theme' => 'pink'])->assertStatus(422);
        $this->putJson('/api/v1/app/family/cashier/menu', ['accent' => 'red'])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $screen = ['X-Zad-Screen' => $token];
        $feed = $this->withHeaders($screen)->getJson('/api/v1/screens/feed')->assertOk()->json('data');
        $this->assertSame('night', $feed['menu']['theme']);
        $this->assertSame('list', $feed['menu']['layout']);
        $names = collect($feed['categories'])->flatMap(fn ($c) => collect($c['products'])->pluck('name'))->all();
        $this->assertEqualsCanonicalizing(['حبحبوه', 'كركديه'], $names);
        $this->assertTrue(collect($feed['categories'][0]['products'])->firstWhere('name', 'كركديه')['sold_out']);
        $this->assertSame('حبحبوه', $feed['offer_product']['name']);
        $this->assertStringEndsWith('/stores/'.$this->store->id, $feed['zad']['store_url']);
        $this->withHeaders(['X-Zad-Screen' => 'nope'])->getJson('/api/v1/screens/feed')->assertStatus(401);

        // No sale yet: no receipt. The owner sells at the counter: the QR shows for a minute.
        $this->withHeaders($screen)->getJson('/api/v1/screens/receipt')->assertOk()->assertJsonPath('data', null);
        $this->flushHeaders();
        Sanctum::actingAs($this->owner);
        $sold = $this->postJson('/api/v1/app/family/cashier/sales', [
            'items' => [['product_id' => $a->id, 'quantity' => 2]], 'payment_method' => 'cash', 'channel' => 'shop',
        ])->assertCreated()->json('data');
        $this->app['auth']->forgetGuards();
        $this->withHeaders($screen)->getJson('/api/v1/screens/receipt')->assertOk()
            ->assertJsonPath('data.number', $sold['number'])->assertJsonPath('data.url', $sold['url'])
            ->assertJsonPath('data.total', 50);
        $this->travel(61)->seconds();
        $this->withHeaders($screen)->getJson('/api/v1/screens/receipt')->assertJsonPath('data', null);

        // The owner turns receipts off for that screen, then unlinks it.
        $this->flushHeaders();
        Sanctum::actingAs($this->owner);
        $id = $this->getJson('/api/v1/app/family/cashier/screens')->assertJsonCount(1, 'data.screens')->json('data.screens.0.id');
        $this->patchJson('/api/v1/app/family/cashier/screens/'.$id, ['show_receipts' => false])->assertOk()
            ->assertJsonPath('data.settings.show_receipts', false);
        $this->postJson('/api/v1/app/family/cashier/sales', [
            'items' => [['product_id' => $a->id, 'quantity' => 1]], 'payment_method' => 'cash', 'channel' => 'shop',
        ])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($screen)->getJson('/api/v1/screens/receipt')->assertJsonPath('data', null);
        $this->flushHeaders();
        Sanctum::actingAs($this->owner);
        $this->deleteJson('/api/v1/app/family/cashier/screens/'.$id)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($screen)->getJson('/api/v1/screens/feed')->assertStatus(401);
    }
}
