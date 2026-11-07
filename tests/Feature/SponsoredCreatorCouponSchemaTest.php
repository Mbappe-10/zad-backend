<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SponsoredCreatorCouponSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sponsored_table_has_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('sponsored_creator_coupons'));

        $this->assertTrue(Schema::hasColumns(
            'sponsored_creator_coupons',
            [
                'id',
                'creator_id',
                'store_id',
                'created_by',
                'code_hash',
                'allowed_products',
                'gross_cap',
                'status',
                'activated_at',
                'expires_at',
                'revoked_at',
                'consumed_at',
                'consumed_order_id',
            ]
        ));
    }

    public function test_sponsored_table_has_unique_code_index(): void
    {
        $indexes = DB::select(
            "PRAGMA index_list('sponsored_creator_coupons')"
        );

        $found = false;

        foreach ($indexes as $index) {
            if ((int) $index->unique !== 1) {
                continue;
            }

            $name = str_replace("'", "''", $index->name);

            $columns = DB::select(
                "PRAGMA index_info('{$name}')"
            );

            $names = array_map(
                fn ($column) => $column->name,
                $columns
            );

            if ($names === ['code_hash']) {
                $found = true;
                break;
            }
        }

        $this->assertTrue(
            $found,
            'Sponsored coupon code_hash must have a unique index.'
        );
    }

    public function test_sponsored_table_has_required_foreign_keys(): void
    {
        $keys = DB::select(
            "PRAGMA foreign_key_list('sponsored_creator_coupons')"
        );

        $actual = [];

        foreach ($keys as $key) {
            $actual[$key->from] = $key->table;
        }

        $this->assertSame('launch_creators', $actual['creator_id'] ?? null);
        $this->assertSame('stores', $actual['store_id'] ?? null);
        $this->assertSame('users', $actual['created_by'] ?? null);
        $this->assertSame('orders', $actual['consumed_order_id'] ?? null);
    }
}