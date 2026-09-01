<?php

// ZAD_FINAL_DELIVERY_V2
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_feedback_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_rating_id')->nullable()->constrained('order_ratings')->cascadeOnDelete();
            $table->string('subject_type', 20)->index();
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->string('category', 50)->index();
            $table->text('details');
            $table->string('status', 20)->default('new')->index();
            $table->boolean('visible_to_subject')->default(true);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'subject_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_feedback_items');
    }
};
