<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional VAT details of a store (trade name + 15-digit VAT number) and, on each
 * cashier sale, the buyer's details and the VAT charged, so a VAT-registered family
 * can issue a tax invoice its business customers can reclaim.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('stores')) {
            Schema::table('stores', function (Blueprint $table): void {
                if (! Schema::hasColumn('stores', 'tax_trade_name')) {
                    $table->string('tax_trade_name', 150)->nullable();
                }
                if (! Schema::hasColumn('stores', 'vat_number')) {
                    $table->string('vat_number', 15)->nullable();
                }
            });
        }
        if (Schema::hasTable('sale_receipts')) {
            Schema::table('sale_receipts', function (Blueprint $table): void {
                foreach ([
                    'customer_name' => fn () => $table->string('customer_name', 120)->nullable(),
                    'customer_vat_number' => fn () => $table->string('customer_vat_number', 15)->nullable(),
                    'seller_trade_name' => fn () => $table->string('seller_trade_name', 150)->nullable(),
                    'seller_vat_number' => fn () => $table->string('seller_vat_number', 15)->nullable(),
                    'vat_rate' => fn () => $table->decimal('vat_rate', 5, 2)->nullable(),
                    'vat_amount' => fn () => $table->decimal('vat_amount', 12, 2)->default(0),
                ] as $column => $add) {
                    if (! Schema::hasColumn('sale_receipts', $column)) {
                        $add();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Additive only: tax records are kept.
    }
};
