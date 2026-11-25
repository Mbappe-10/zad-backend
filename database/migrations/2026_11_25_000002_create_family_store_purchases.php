<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('family_store_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('productive_family_id')->constrained()->cascadeOnDelete();
            $table->string('plan_code', 20);
            $table->unsignedBigInteger('amount_halalas');
            $table->unsignedInteger('duration_days');
            $table->string('currency', 3)->default('SAR');
            $table->string('mode', 10);
            $table->string('status', 20)->default('creating');
            $table->uuid('invoice_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->string('callback_token_hash', 64)->unique();
            $table->uuid('provider_payment_id')->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('grant_id')->nullable()->unique()->constrained('family_store_grants')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['productive_family_id', 'status', 'created_at'], 'family_purchase_latest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_store_purchases');
    }
};
