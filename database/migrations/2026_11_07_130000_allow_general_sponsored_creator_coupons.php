<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE sponsored_creator_coupons ALTER COLUMN creator_id DROP NOT NULL');
            DB::statement('ALTER TABLE sponsored_creator_coupons ALTER COLUMN store_id DROP NOT NULL');
            return;
        }
        if ($driver === 'sqlite') {
            Schema::table('sponsored_creator_coupons', function (Blueprint $table): void {
                $table->foreignId('creator_id')->nullable()->change();
                $table->foreignId('store_id')->nullable()->change();
            });
            return;
        }
        throw new \RuntimeException('Unsupported database driver for sponsored coupon migration: ' . $driver);
    }

    public function down(): void
    {
        // Deliberately no-op: existing general coupons may contain null foreign keys.
    }
};
