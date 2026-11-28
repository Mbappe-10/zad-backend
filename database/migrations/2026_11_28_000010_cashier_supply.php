<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier Zad Pro supply chain: suppliers, purchase invoices that put stock in and
 * update costs, branches (shops, stalls, a warehouse) with their own stock, and
 * transfers between them. The main branch holds whatever the other branches do not.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('cashier_branches')) {
            Schema::create('cashier_branches', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->index();
                $table->string('name', 60);
                $table->string('kind', 20)->default('shop'); // main | shop | stall | warehouse
                $table->string('address', 160)->nullable();
                $table->boolean('is_main')->default(false);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cashier_branch_stock')) {
            Schema::create('cashier_branch_stock', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->index();
                $table->unsignedBigInteger('branch_id');
                $table->string('item_key', 24); // p:<product id> | m:<material id>
                $table->decimal('quantity', 14, 3)->default(0);
                $table->decimal('par_level', 14, 3)->nullable(); // keep at least this much here
                $table->timestamps();
                $table->unique(['branch_id', 'item_key']);
            });
        }

        if (! Schema::hasTable('cashier_suppliers')) {
            Schema::create('cashier_suppliers', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->index();
                $table->string('name', 80);
                $table->string('contact_name', 80)->nullable();
                $table->string('phone', 20)->nullable();
                $table->string('vat_number', 20)->nullable();
                $table->unsignedSmallInteger('payment_days')->default(0); // 0 = pay on receipt
                $table->string('notes', 300)->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cashier_purchases')) {
            Schema::create('cashier_purchases', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->index();
                $table->unsignedBigInteger('supplier_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('number', 20);
                $table->string('supplier_ref', 40)->nullable();
                $table->string('status', 12)->default('draft'); // draft | received | cancelled
                $table->boolean('auto')->default(false);
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('vat', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->decimal('paid', 12, 2)->default(0);
                $table->date('due_date')->nullable();
                $table->string('note', 300)->nullable();
                $table->timestamp('received_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['store_id', 'number']);
            });
        }

        if (! Schema::hasTable('cashier_purchase_items')) {
            Schema::create('cashier_purchase_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('purchase_id')->index();
                $table->unsignedBigInteger('material_id')->nullable();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('name', 80);
                $table->string('unit', 30)->nullable();
                $table->decimal('quantity', 14, 3);
                $table->decimal('unit_cost', 12, 3)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cashier_supplier_payments')) {
            Schema::create('cashier_supplier_payments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->index();
                $table->unsignedBigInteger('supplier_id')->index();
                $table->unsignedBigInteger('purchase_id')->nullable()->index();
                $table->decimal('amount', 12, 2);
                $table->string('method', 20)->default('cash'); // cash | transfer | card
                $table->string('note', 200)->nullable();
                $table->timestamp('paid_at');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cashier_transfers')) {
            Schema::create('cashier_transfers', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->index();
                $table->string('number', 20);
                $table->unsignedBigInteger('from_branch_id');
                $table->unsignedBigInteger('to_branch_id');
                $table->string('status', 12)->default('draft'); // draft | done | cancelled
                $table->boolean('auto')->default(false);
                $table->json('items');
                $table->string('note', 300)->nullable();
                $table->timestamp('done_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['store_id', 'number']);
            });
        }

        if (Schema::hasTable('cashier_devices') && ! Schema::hasColumn('cashier_devices', 'branch_id')) {
            Schema::table('cashier_devices', function (Blueprint $table): void {
                $table->unsignedBigInteger('branch_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('cashier_materials')) {
            Schema::table('cashier_materials', function (Blueprint $table): void {
                if (! Schema::hasColumn('cashier_materials', 'supplier_id')) {
                    $table->unsignedBigInteger('supplier_id')->nullable()->index();
                }
                if (! Schema::hasColumn('cashier_materials', 'reorder_quantity')) {
                    $table->decimal('reorder_quantity', 14, 3)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['cashier_transfers', 'cashier_supplier_payments', 'cashier_purchase_items', 'cashier_purchases',
            'cashier_suppliers', 'cashier_branch_stock', 'cashier_branches'] as $t) {
            Schema::dropIfExists($t);
        }
        if (Schema::hasTable('cashier_devices') && Schema::hasColumn('cashier_devices', 'branch_id')) {
            Schema::table('cashier_devices', fn (Blueprint $t) => $t->dropColumn('branch_id'));
        }
        if (Schema::hasTable('cashier_materials')) {
            Schema::table('cashier_materials', function (Blueprint $t): void {
                foreach (['supplier_id', 'reorder_quantity'] as $c) {
                    if (Schema::hasColumn('cashier_materials', $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
    }
};
