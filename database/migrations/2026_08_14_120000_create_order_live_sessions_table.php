<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_live_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();

            $table
                ->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            $table
                ->foreignId('productive_family_id')
                ->constrained()
                ->cascadeOnDelete();

            $table
                ->foreignId('started_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('room_name', 191)->unique();
            $table->string('publisher_identity', 191)->nullable();
            $table->string('status', 30)->default('waiting')->index();

            $table->unsignedInteger('viewer_count')->default(0);
            $table->unsignedInteger('peak_viewers')->default(0);

            $table->string('quality_profile', 30)->default('adaptive');
            $table->json('compliance')->nullable();
            $table->json('metadata')->nullable();

            $table->string('final_photo_path')->nullable();
            $table->timestamp('final_photo_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 100)->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['productive_family_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_live_sessions');
    }
};