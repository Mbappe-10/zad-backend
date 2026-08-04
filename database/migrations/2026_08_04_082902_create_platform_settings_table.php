<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_settings')) {
            return;
        }

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();

            $table->string('group', 100);
            $table->string('key', 150);

            $table->json('value')->nullable();

            $table
                ->boolean('is_sensitive')
                ->default(false);

            $table
                ->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['group', 'key'],
                'platform_settings_group_key_unique',
            );

            $table->index(
                'group',
                'platform_settings_group_index',
            );

            $table->index(
                'is_sensitive',
                'platform_settings_sensitive_index',
            );

            $table->index(
                'updated_at',
                'platform_settings_updated_at_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};