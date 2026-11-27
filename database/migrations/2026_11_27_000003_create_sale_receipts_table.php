<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sale_receipts')) {
            return;
        }

        Schema::create('sale_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 40)->nullable()->unique();
            $table->string('public_token', 64)->unique();
            $table->string('sale_type', 20)->index();      // platform | quick | cashier
            $table->string('channel', 40)->index();        // online | door | bazaar | <cashier name>
            $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->unsignedBigInteger('productive_family_id')->nullable()->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('customer_phone', 32)->nullable()->index();
            $table->string('store_name')->nullable();
            $table->string('payment_method', 40)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('delivery_fee', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->json('items')->nullable();
            $table->string('status', 20)->default('paid')->index(); // paid | refunded | cancelled
            $table->timestamp('issued_at')->index();
            $table->timestamp('opened_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_receipts');
    }
};
