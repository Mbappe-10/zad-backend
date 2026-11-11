<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class MarketingPartnerIsolationTest extends TestCase
{
    private function partner(int $id): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = $id;
        $user->shouldReceive('hasRole')->with('marketing_partner')->andReturn(true);
        $user->shouldReceive('hasPermission')->with('coupons.view')->andReturn(true);
        Sanctum::actingAs($user);
    }

    private function fixtures(): array
    {
        Schema::create('launch_creators', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id')->nullable(); $t->string('code'); $t->string('name');
            $t->string('status')->default('active'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('platform_records', function (Blueprint $t) {
            $t->id(); $t->string('resource'); $t->string('external_key')->nullable();
            $t->string('status')->default('active'); $t->json('payload');
            $t->unsignedBigInteger('created_by')->nullable(); $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('order_coupon_usages', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('coupon_code'); $t->string('status'); $t->decimal('discount_amount', 12, 2);
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('customer_id')->nullable(); $t->string('payment_status');
            $t->string('status'); $t->decimal('total', 12, 2); $t->softDeletes();
        });
        Schema::create('refunds', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->string('status');
        });
        Schema::create('sponsored_creator_coupons', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('creator_id');
        });

        Schema::create('sponsored_order_fundings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('coupon_id');
            $t->unsignedBigInteger('order_id');
            $t->string('funding_source');
            $t->string('status');
            $t->decimal('funded_total', 12, 2);
        });
        DB::table('launch_creators')->insert([
            ['id'=>1,'user_id'=>101,'code'=>'CREATOR_A','name'=>'Partner A'],
            ['id'=>2,'user_id'=>202,'code'=>'CREATOR_B','name'=>'Partner B'],
        ]);
        $coupon = function ($id, $code) {
            DB::table('platform_records')->insert(['id'=>$id,'resource'=>'coupons-offers',
                'external_key'=>$code,'payload'=>json_encode(['kind'=>'coupon','code'=>$code,'nameAr'=>$code]),
                'status'=>'active']);
        };
        $coupon(11,'PARTNER_A'); $coupon(22,'PARTNER_B');
        $link = function ($id, $creator, $code, $from, $until = null) {
            DB::table('platform_records')->insert(['id'=>$id,'resource'=>'creator-coupon-links',
                'external_key'=>'link-'.$id,'status'=>'active',
                'payload'=>json_encode(['creator_id'=>$creator,'coupon_code'=>$code,'linked_at'=>$from,'unlinked_at'=>$until])]);
        };
        $link(31,1,'PARTNER_A','2026-10-01 00:00:00','2026-10-15 00:00:00');
        $link(32,2,'PARTNER_B','2026-10-01 00:00:00');
        DB::table('orders')->insert([
            ['id'=>1001,'customer_id'=>7,'payment_status'=>'paid','status'=>'completed','total'=>50],
            ['id'=>1002,'customer_id'=>8,'payment_status'=>'paid','status'=>'completed','total'=>70],
            ['id'=>1003,'customer_id'=>9,'payment_status'=>'paid','status'=>'completed','total'=>90],
        ]);
        DB::table('order_coupon_usages')->insert([
            ['order_id'=>1001,'customer_id'=>7,'coupon_code'=>'PARTNER_A','status'=>'applied','discount_amount'=>5,'created_at'=>'2026-10-05 12:00:00'],
            ['order_id'=>1002,'customer_id'=>8,'coupon_code'=>'PARTNER_A','status'=>'applied','discount_amount'=>7,'created_at'=>'2026-10-20 12:00:00'],
            ['order_id'=>1003,'customer_id'=>9,'coupon_code'=>'PARTNER_B','status'=>'applied','discount_amount'=>9,'created_at'=>'2026-10-06 12:00:00'],
        ]);
        return [11,22];
    }

    public function test_each_partner_sees_only_own_coupon_and_cannot_access_other_report(): void
    {
        [$a,$b] = $this->fixtures();
        $this->partner(101);
        $this->getJson('/api/admin/partner/coupons')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.code','PARTNER_A');
        $this->getJson("/api/admin/partner/coupons/$b/report")->assertNotFound();
        $this->partner(202);
        $this->getJson('/api/admin/partner/coupons')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.code','PARTNER_B');
        $this->getJson("/api/admin/partner/coupons/$a/report")->assertNotFound();
    }

    public function test_report_counts_only_link_window_and_hides_internal_commissions(): void
    {
        [$a] = $this->fixtures();
        $this->partner(101);
        $data = $this->getJson("/api/admin/partner/coupons/$a/report")->assertOk()->json('data');
        $this->assertSame(1, $data['recorded_uses']);
        $this->assertSame(1, $data['paid_order_count']);
        $this->assertEquals(50, $data['paid_order_gmv']);
        $this->assertEquals(5, $data['discount_on_paid_orders']);
        foreach (['platform_total','family_commission','driver_commission','creator_commission_amount'] as $secret) {
            $this->assertArrayNotHasKey($secret, $data);
        }
    }

    public function test_sponsored_summary_is_isolated_per_partner(): void
    {
        [$a, $b] = $this->fixtures();

        DB::table('sponsored_creator_coupons')->insert([
            ['id' => 501, 'creator_id' => 1],
            ['id' => 502, 'creator_id' => 2],
        ]);

        DB::table('orders')->insert([
            ['id' => 2001, 'customer_id' => 11, 'payment_status' => 'unpaid', 'status' => 'completed', 'total' => 0],
            ['id' => 2002, 'customer_id' => 12, 'payment_status' => 'unpaid', 'status' => 'completed', 'total' => 0],
        ]);

        DB::table('sponsored_order_fundings')->insert([
            [
                'coupon_id' => 501,
                'order_id' => 2001,
                'funding_source' => 'zad_platform',
                'status' => 'committed',
                'funded_total' => 80,
            ],
            [
                'coupon_id' => 502,
                'order_id' => 2002,
                'funding_source' => 'zad_platform',
                'status' => 'committed',
                'funded_total' => 150,
            ],
        ]);

        $this->partner(101);

        $this->getJson("/api/admin/partner/coupons/$a/report")
            ->assertOk()
            ->assertJsonPath('data.sponsored_summary.scope', 'creator')
            ->assertJsonPath('data.sponsored_summary.sponsored_order_count', 1)
            ->assertJsonPath('data.sponsored_summary.sponsored_funded_total', 80);

        $this->getJson("/api/admin/partner/coupons/$b/report")
            ->assertNotFound();

        $this->partner(202);

        $this->getJson("/api/admin/partner/coupons/$b/report")
            ->assertOk()
            ->assertJsonPath('data.sponsored_summary.scope', 'creator')
            ->assertJsonPath('data.sponsored_summary.sponsored_order_count', 1)
            ->assertJsonPath('data.sponsored_summary.sponsored_funded_total', 150);

        $this->getJson("/api/admin/partner/coupons/$a/report")
            ->assertNotFound();
    }
}
