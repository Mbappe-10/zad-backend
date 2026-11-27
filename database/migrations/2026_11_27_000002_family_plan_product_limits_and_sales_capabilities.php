<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds plan capabilities for product limits and sales tools. Only keys that a
 * plan does not have yet are added, so values the owner already edited from
 * the dashboard are never overwritten.
 */
return new class extends Migration {
    private const DEFAULTS = [
        'free' => ['max_products' => 4, 'sales_insights' => false, 'sales_pro' => false],
        'silver' => ['max_products' => 10, 'sales_insights' => true, 'sales_pro' => false],
        'gold' => ['max_products' => 20, 'sales_insights' => true, 'sales_pro' => true],
    ];

    public function up(): void
    {
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
        // Capabilities stay; the owner may have edited them.
    }
};
