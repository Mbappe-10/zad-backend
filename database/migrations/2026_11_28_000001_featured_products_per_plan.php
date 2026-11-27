<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Featured dishes: a flag on products and a per-plan limit (gold 6 by default).
 * Existing capability values edited by the owner are never overwritten.
 */
return new class extends Migration {
    private const DEFAULTS = [
        'free' => ['featured_products' => 0],
        'silver' => ['featured_products' => 0],
        'gold' => ['featured_products' => 6],
    ];

    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'is_featured')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->boolean('is_featured')->default(false)->index();
            });
        }
        if (! Schema::hasTable('family_store_plans')) {
            return;
        }
        foreach (self::DEFAULTS as $code => $defaults) {
            $row = DB::table('family_store_plans')->where('code', $code)->first();
            if (! $row) {
                continue;
            }
            $capabilities = json_decode((string) $row->capabilities, true);
            $capabilities = is_array($capabilities) ? $capabilities : [];
            $merged = $capabilities + $defaults;
            if ($merged !== $capabilities) {
                DB::table('family_store_plans')->where('code', $code)->update([
                    'capabilities' => json_encode($merged),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'is_featured')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('is_featured'));
        }
    }
};
