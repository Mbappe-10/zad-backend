<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branding_setting_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branding_setting_id')
                ->nullable()
                ->constrained('branding_settings')
                ->nullOnDelete();

            $table->unsignedBigInteger('version_number');

            $table->string('action', 50)->default('updated');

            $table->text('change_summary')->nullable();

            $table->json('settings_snapshot');

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('creator_name')->nullable();
            $table->string('creator_email')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index([
                'branding_setting_id',
                'version_number',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_setting_versions');
    }
};
