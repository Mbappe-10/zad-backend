<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_setting_audits')) {
            return;
        }

        Schema::create('platform_setting_audits', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('group', 100);
            $table->string('key', 150);

            $table
                ->json('old_value')
                ->nullable();

            $table
                ->json('new_value')
                ->nullable();

            $table
                ->string('status', 50)
                ->default('published');

            $table
                ->string('action', 50)
                ->default('updated');

            $table
                ->text('reason')
                ->nullable();

            $table
                ->string('ip_address', 45)
                ->nullable();

            $table
                ->text('user_agent')
                ->nullable();

            $table
                ->timestamp('approved_at')
                ->nullable();

            $table
                ->timestamp('published_at')
                ->nullable();

            $table->timestamps();

            $table->index(
                ['group', 'key'],
                'platform_setting_audits_group_key_index',
            );

            $table->index(
                'status',
                'platform_setting_audits_status_index',
            );

            $table->index(
                'action',
                'platform_setting_audits_action_index',
            );

            $table->index(
                'created_at',
                'platform_setting_audits_created_at_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_setting_audits');
    }
};