<?php

// ZAD_DELIVERY_OTP_V1
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_delivery_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('code');
            $table->string('code_hash');
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('verified_at')->nullable()->index();
            $table->foreignId('verified_by_driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('order_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_session_id', 36)->nullable()->index();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('arrival_condition', 30);
            $table->unsignedTinyInteger('driver_score');
            $table->json('driver_tags')->nullable();
            $table->unsignedTinyInteger('food_quality_score');
            $table->unsignedTinyInteger('cleanliness_packaging_score');
            $table->unsignedTinyInteger('order_accuracy_score');
            $table->string('comment', 500)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->index(['driver_id', 'driver_score']);
            $table->index(['store_id', 'food_quality_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_ratings');
        Schema::dropIfExists('order_delivery_verifications');
    }
};