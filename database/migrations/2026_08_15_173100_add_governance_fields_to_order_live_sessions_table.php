<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_live_sessions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('preparation_minutes')->default(15);
            $table->unsignedTinyInteger('grace_minutes')->default(5);
            $table->unsignedSmallInteger('extended_minutes')->default(0);
            $table->timestamp('scheduled_end_at')->nullable()->index();
            $table->timestamp('warning_sent_at')->nullable();
            $table->foreignId('admin_updated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('admin_action_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_live_sessions', function (Blueprint $table): void {
            $table->dropForeign(['admin_updated_by_user_id']);
            $table->dropIndex(['scheduled_end_at']);
            $table->dropColumn([
                'preparation_minutes',
                'grace_minutes',
                'extended_minutes',
                'scheduled_end_at',
                'warning_sent_at',
                'admin_updated_by_user_id',
                'admin_action_reason',
            ]);
        });
    }
};