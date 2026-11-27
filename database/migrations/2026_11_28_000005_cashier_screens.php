<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier Zad Pro screens: the family's menu design (one per store) and which
 * cashier device made each sale, so a menu screen shows only its own counter's
 * receipt QR right after the customer pays.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('cashier_menus')) {
            Schema::create('cashier_menus', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->unique();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }
        if (Schema::hasTable('sale_receipts') && ! Schema::hasColumn('sale_receipts', 'cashier_device_id')) {
            Schema::table('sale_receipts', function (Blueprint $table): void {
                $table->unsignedBigInteger('cashier_device_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_receipts') && Schema::hasColumn('sale_receipts', 'cashier_device_id')) {
            Schema::table('sale_receipts', function (Blueprint $table): void {
                $table->dropColumn('cashier_device_id');
            });
        }
        Schema::dropIfExists('cashier_menus');
    }
};
