<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_schedules')) {
            return;
        }

        Schema::create('order_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->timestamp('delivery_at');
            $table->string('occasion', 120);
            $table->unsignedInteger('guest_count')->nullable();
            $table->string('family_status', 32)->default('pending');
            $table->text('decline_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['family_status', 'delivery_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_schedules');
    }
};