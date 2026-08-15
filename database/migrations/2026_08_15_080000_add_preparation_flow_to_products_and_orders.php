<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'preparation_mode')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('preparation_mode', 30)
                    ->default('made_to_order')
                    ->after('preparation_minutes')
                    ->index();
            });
        }

        if (! Schema::hasColumn('orders', 'fulfillment_mode')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->string('fulfillment_mode', 30)
                    ->default('live_preparation')
                    ->after('payment_status')
                    ->index();
            });
        }

        if (! Schema::hasColumn('order_items', 'preparation_mode')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->string('preparation_mode', 30)
                    ->default('made_to_order')
                    ->after('product_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'preparation_mode')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->dropColumn('preparation_mode');
            });
        }

        if (Schema::hasColumn('orders', 'fulfillment_mode')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropColumn('fulfillment_mode');
            });
        }

        if (Schema::hasColumn('products', 'preparation_mode')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('preparation_mode');
            });
        }
    }
};