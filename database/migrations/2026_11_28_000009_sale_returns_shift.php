<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Returns belong to the employee's shift, so cash given back comes off the cash she must hand over. */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('sale_returns') && ! Schema::hasColumn('sale_returns', 'cashier_shift_id')) {
            Schema::table('sale_returns', function (Blueprint $table): void {
                $table->unsignedBigInteger('cashier_shift_id')->nullable()->index();
                $table->unsignedBigInteger('cashier_device_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sale_returns', 'cashier_shift_id')) {
            Schema::table('sale_returns', function (Blueprint $table): void {
                $table->dropColumn(['cashier_shift_id', 'cashier_device_id']);
            });
        }
    }
};
