<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketingPartnerSponsoredFundingTest extends TestCase
{
    public function test_sponsored_totals_are_isolated_and_exclude_invalid_orders(): void
    {
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

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('status');
            $t->softDeletes();
        });

        DB::table('sponsored_creator_coupons')->insert([
            ['id' => 1, 'creator_id' => 101],
            ['id' => 2, 'creator_id' => 202],
        ]);

        DB::table('orders')->insert([
            ['id' => 1, 'status' => 'completed'],
            ['id' => 2, 'status' => 'completed'],
            ['id' => 3, 'status' => 'cancelled'],
            ['id' => 4, 'status' => 'completed'],
            ['id' => 5, 'status' => 'completed'],
        ]);

        DB::table('sponsored_order_fundings')->insert([
            ['coupon_id' => 1, 'order_id' => 1, 'funding_source' => 'zad_platform', 'status' => 'committed', 'funded_total' => 80],
            ['coupon_id' => 2, 'order_id' => 2, 'funding_source' => 'zad_platform', 'status' => 'committed', 'funded_total' => 150],
            ['coupon_id' => 1, 'order_id' => 3, 'funding_source' => 'zad_platform', 'status' => 'committed', 'funded_total' => 40],
            ['coupon_id' => 1, 'order_id' => 4, 'funding_source' => 'zad_platform', 'status' => 'pending', 'funded_total' => 60],
            ['coupon_id' => 1, 'order_id' => 5, 'funding_source' => 'other', 'status' => 'committed', 'funded_total' => 30],
        ]);

        $result = DB::table('sponsored_order_fundings as f')
            ->join('sponsored_creator_coupons as c', 'c.id', '=', 'f.coupon_id')
            ->join('orders as o', 'o.id', '=', 'f.order_id')
            ->where('c.creator_id', 101)
            ->where('f.funding_source', 'zad_platform')
            ->where('f.status', 'committed')
            ->whereNotIn('o.status', ['cancelled', 'rejected'])
            ->whereNull('o.deleted_at')
            ->selectRaw('COUNT(DISTINCT f.order_id) as order_count')
            ->selectRaw('COALESCE(SUM(f.funded_total), 0) as funded_total')
            ->first();

        $this->assertEquals(1, $result->order_count);
        $this->assertEquals(80, $result->funded_total);
    }
}