<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_setting_audits')) {
            return;
        }

        if (! Schema::hasColumn('platform_setting_audits', 'status')) {
            Schema::table('platform_setting_audits', function (Blueprint $table): void {
                $table->string('status', 50)->default('published');
            });
        }

        if (! Schema::hasColumn('platform_setting_audits', 'action')) {
            Schema::table('platform_setting_audits', function (Blueprint $table): void {
                $table->string('action', 50)->default('updated');
            });
        }

        if (! Schema::hasColumn('platform_setting_audits', 'reason')) {
            Schema::table('platform_setting_audits', function (Blueprint $table): void {
                $table->text('reason')->nullable();
            });
        }

        if (! Schema::hasColumn('platform_setting_audits', 'approved_at')) {
            Schema::table('platform_setting_audits', function (Blueprint $table): void {
                $table->timestamp('approved_at')->nullable();
            });
        }

        if (! Schema::hasColumn('platform_setting_audits', 'published_at')) {
            Schema::table('platform_setting_audits', function (Blueprint $table): void {
                $table->timestamp('published_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // إبقاء الأعمدة عند التراجع لحماية بيانات سجل الحوكمة.
    }
};