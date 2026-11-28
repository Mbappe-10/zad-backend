<?php

namespace Tests\Feature\Api;

use App\Models\AppProfile;
use App\Models\CashierBatch;
use App\Models\CashierMaterial;
use App\Models\CashierRecipeItem;
use App\Models\FamilyCashierGrant;
use App\Models\FamilyStoreGrant;
use App\Models\Product;
use App\Models\ProductiveFamily;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashierOperationsTest extends TestCase
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
            'code' => 'PF-OPS', 'owner_name' => 'أم سارة', 'phone' => '0551118888', 'status' => 'approved',
        ]);
        $this->store = Store::query()->create([
            'productive_family_id' => $this->family->id, 'name_ar' => 'سوبو',
            'slug' => 'ops-store', 'status' => 'active', 'is_open' => true,
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

    /** A juice that uses 0.2 kg watermelon per bottle, with 10 kg in stock. */
    private function juice(): Product
    {
        $p = $this->product('حبحبوه', 25);
        $melon = CashierMaterial::query()->create(['store_id' => $this->store->id, 'name' => 'بطيخ', 'unit' => 'كجم', 'quantity' => 10, 'unit_cost' => 4]);
        CashierRecipeItem::query()->create(['product_id' => $p->id, 'material_id' => $melon->id, 'per_unit' => 0.2]);

        return $p;
    }

    private function station(string $station, string $role, array $extra = []): array
    {
        $member = $this->postJson('/api/v1/app/family/cashier/staff', ['name' => $role === 'supervisor' ? 'سارة' : 'ريم', 'role' => $role, 'pin' => '1234'] + $extra)
            ->assertCreated()->json('data');
        app(\App\Services\App\CashierStaffService::class)->ownerRow($this->store)->forceFill(['pin_hash' => Hash::make('4321')])->save();
        $code = $this->postJson('/api/v1/app/family/cashier/link-codes', ['station' => $station])->assertCreated()->json('data.code');
        $token = $this->postJson('/api/v1/devices/link', ['code' => $code])->assertCreated()->json('data.token');
        $session = $this->withHeaders(['X-Cashier-Device' => $token])
            ->postJson('/api/v1/cashier-device/unlock', ['staff_id' => $member['id'], 'pin' => '1234'])->assertOk()->json('data.staff_token');
        $this->flushHeaders();

        return ['X-Cashier-Device' => $token, 'X-Cashier-Staff' => $session, 'id' => $member['id']];
    }

    public function test_packaging_goes_straight_to_stock_and_takes_raw_materials_when_no_approval_is_required(): void
    {
        $p = $this->juice();
        $this->getJson('/api/v1/app/family/cashier/batches')->assertForbidden();
        $this->pro();
        $pack = $this->station('packaging', 'packaging');
        $h = array_diff_key($pack, ['id' => 1]);
        Sanctum::actingAs($this->owner);
        $this->app['auth']->forgetGuards();

        $list = $this->withHeaders($h)->getJson('/api/v1/cashier-device/cashier/batches')->assertOk()->json('data');
        $this->assertSame(50, collect($list['products'])->firstWhere('id', $p->id)['can_make']);
        $batch = $this->withHeaders($h)->postJson('/api/v1/cashier-device/cashier/batches', [
            'product_id' => $p->id, 'quantity' => 20, 'location' => 'الثلاجة ٢', 'ready_in_minutes' => 10,
            'note' => 'تبرد بعد ١٠ دقايق، وتقدرون تعطونها نص باردة',
        ])->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.packed_by', 'ريم')->json('data');
        $this->assertSame('بطيخ', $batch['materials_used'][0]['name']);
        $this->assertEqualsWithDelta(4.0, $batch['materials_used'][0]['amount'], 0.001);
        $this->assertGreaterThan(500, $batch['ready_in_seconds']);
        $this->assertSame(20, $p->fresh()->stock_quantity);
        $this->assertEqualsWithDelta(6.0, (float) CashierMaterial::query()->where('name', 'بطيخ')->value('quantity'), 0.001);

        // The cashier sees «+20» with the note and the countdown.
        $live = $this->withHeaders($h)->getJson('/api/v1/cashier-device/cashier/batches/live')->assertOk()->json('data.0');
        $this->assertSame(20, $live['approved_quantity']);
        $this->assertSame('الثلاجة ٢', $live['location']);

        // Photos are off until the owner turns them on, and the photo is small.
        $this->flushHeaders();
        Storage::fake('public');
        $photo = UploadedFile::fake()->image('b.jpg', 800, 600)->size(90);
        $this->withHeaders($h)->post('/api/v1/cashier-device/cashier/batches', ['product_id' => $p->id, 'quantity' => 1, 'photo' => $photo], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->flushHeaders();
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/app/family/cashier/operations-settings', ['photos_enabled' => true])->assertOk()->assertJsonPath('data.photos_enabled', true);
        $this->app['auth']->forgetGuards();
        $withPhoto = $this->withHeaders($h)->post('/api/v1/cashier-device/cashier/batches', ['product_id' => $p->id, 'quantity' => 1, 'photo' => $photo], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.has_photo', true)->json('data.id');
        $this->flushHeaders();

        // Scheduled clean-up keeps the record and deletes the old photo.
        CashierBatch::query()->whereKey($withPhoto)->update(['created_at' => now()->subDays(40)]);
        $this->artisan('zad:cashier-purge-media')->assertSuccessful();
        $this->assertNotNull(CashierBatch::query()->find($withPhoto)->photo_deleted_at);
        $this->assertNull(CashierBatch::query()->find($withPhoto)->photo_path);
    }

    public function test_a_required_supervisor_holds_batches_until_approved_and_the_platform_owner_can_purge_media(): void
    {
        $p = $this->juice();
        $this->pro();
        $pack = $this->station('packaging', 'packaging');
        Sanctum::actingAs($this->owner);
        $sup = $this->station('supervisor', 'supervisor', ['approval_required' => true]);
        $this->app['auth']->forgetGuards();
        $packH = array_diff_key($pack, ['id' => 1]);
        $supH = array_diff_key($sup, ['id' => 1]);

        $id = $this->withHeaders($packH)->postJson('/api/v1/cashier-device/cashier/batches', ['product_id' => $p->id, 'quantity' => 12])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertSame(0, (int) $p->fresh()->stock_quantity);
        // Packaging staff cannot approve their own batch.
        $this->withHeaders($packH)->postJson("/api/v1/cashier-device/cashier/batches/{$id}/approve")->assertForbidden();
        $this->flushHeaders();

        $this->withHeaders($supH)->getJson('/api/v1/cashier-device/cashier/batches?status=pending')->assertOk()->assertJsonPath('data.pending', 1);
        $this->withHeaders($supH)->postJson("/api/v1/cashier-device/cashier/batches/{$id}/approve", ['quantity' => 10])
            ->assertOk()->assertJsonPath('data.approved_quantity', 10)->assertJsonPath('data.reviewed_by', 'سارة');
        $this->withHeaders($supH)->postJson("/api/v1/cashier-device/cashier/batches/{$id}/approve")->assertStatus(422);
        $this->flushHeaders();
        $this->assertSame(10, $p->fresh()->stock_quantity);

        // Optional supervisor: batches go straight in again.
        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/v1/app/family/cashier/staff/{$sup['id']}", ['approval_required' => false])->assertOk()
            ->assertJsonPath('data.approval_required', false);
        $this->app['auth']->forgetGuards();
        $this->withHeaders($packH)->postJson('/api/v1/cashier-device/cashier/batches', ['product_id' => $p->id, 'quantity' => 5])
            ->assertCreated()->assertJsonPath('data.status', 'approved');
        $this->flushHeaders();
    }
}
