<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsored_creator_coupons', function (Blueprint $table) {
            $table->id();

            $table->foreignId('creator_id')
                ->constrained('launch_creators')
                ->restrictOnDelete();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('code_hash', 64)->unique();

            $table->json('allowed_products');
            $table->decimal('gross_cap', 12, 2);

            $table->string('status', 24)->default('draft');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('consumed_at')->nullable();

            $table->foreignId('consumed_order_id')
                ->nullable()
                ->unique()
                ->constrained('orders')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(['creator_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsored_creator_coupons');
    }
};