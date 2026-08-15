<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_broadcast_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('default_grace_minutes')->default(5);
            $table->unsignedTinyInteger('warning_before_end_minutes')->default(3);
            $table->unsignedSmallInteger('maximum_extension_minutes')->default(15);
            $table->boolean('auto_end_enabled')->default(true);
            $table->boolean('audio_enabled')->default(false);
            $table->boolean('chat_enabled')->default(false);
            $table->boolean('screen_share_enabled')->default(false);
            $table->foreignId('updated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_broadcast_settings');
    }
};