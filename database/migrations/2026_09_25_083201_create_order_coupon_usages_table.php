<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_coupon_usages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();

            $table->unsignedBigInteger('platform_record_id')->nullable();

            $table->string('coupon_code', 100);

            $table->string('discount_type', 40);
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('max_discount', 12, 2)->default(0);
            $table->decimal('minimum_order', 12, 2)->default(0);

            $table->decimal('subtotal_snapshot', 12, 2)->default(0);
            $table->decimal('delivery_fee_snapshot', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);

            $table->string('status', 30)->default('applied');

            $table->json('coupon_snapshot')->nullable();

            $table->timestamps();

            $table->unique('order_id');
            $table->index('coupon_code');
            $table->index(['customer_id', 'coupon_code']);
            $table->index(['coupon_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_coupon_usages');
    }
};
