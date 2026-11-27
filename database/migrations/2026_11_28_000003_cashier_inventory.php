<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier Zad inventory: one product catalog shared by the online store and the cashier
 * (a product can be cashier-only), tracked stock shown to customers, raw materials and
 * packaging without photos, product recipes and production runs with their cost.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table): void {
                if (! Schema::hasColumn('products', 'cashier_only')) {
                    $table->boolean('cashier_only')->default(false)->index();
                }
                if (! Schema::hasColumn('products', 'track_stock')) {
                    $table->boolean('track_stock')->default(false);
                }
                if (! Schema::hasColumn('products', 'stock_quantity')) {
                    $table->integer('stock_quantity')->nullable();
                }
                if (! Schema::hasColumn('products', 'stock_auto_closed')) {
                    $table->boolean('stock_auto_closed')->default(false);
                }
            });
        }

        if (! Schema::hasTable('cashier_materials')) {
            Schema::create('cashier_materials', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->index();
                $table->string('name', 80);
                $table->string('kind', 20)->default('raw'); // raw | packaging
                $table->string('unit', 30);
                $table->decimal('quantity', 14, 3)->default(0);
                $table->decimal('unit_cost', 12, 2)->default(0);
                $table->decimal('low_alert', 14, 3)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cashier_recipe_items')) {
            Schema::create('cashier_recipe_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('material_id')->index();
                // How much of the material's unit one product uses (1 bag makes 6 cups => 0.16667).
                $table->decimal('per_unit', 14, 5);
                $table->timestamps();
                $table->unique(['product_id', 'material_id']);
            });
        }

        if (! Schema::hasTable('cashier_productions')) {
            Schema::create('cashier_productions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->integer('quantity');
                $table->decimal('unit_cost', 12, 3)->default(0);
                $table->decimal('total_cost', 14, 2)->default(0);
                $table->json('materials')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cashier_stock_moves')) {
            Schema::create('cashier_stock_moves', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->index();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->unsignedBigInteger('material_id')->nullable()->index();
                $table->decimal('change', 14, 3);
                $table->decimal('balance', 14, 3);
                $table->string('reason', 20); // sale_online | sale_quick | production | adjust | purchase
                $table->string('reference', 60)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_stock_moves');
        Schema::dropIfExists('cashier_productions');
        Schema::dropIfExists('cashier_recipe_items');
        Schema::dropIfExists('cashier_materials');
    }
};
