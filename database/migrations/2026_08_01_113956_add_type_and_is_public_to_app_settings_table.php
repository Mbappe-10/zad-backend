<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إضافة الحقول الناقصة إلى جدول إعدادات التطبيق.
     */
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        Schema::table('app_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('app_settings', 'type')) {
                $table
                    ->string('type', 50)
                    ->default('string');
            }

            if (! Schema::hasColumn('app_settings', 'is_public')) {
                $table
                    ->boolean('is_public')
                    ->default(false);
            }
        });
    }

    /**
     * التراجع عن التعديل عند الحاجة.
     */
    public function down(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        Schema::table('app_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('app_settings', 'type')) {
                $table->dropColumn('type');
            }

            if (Schema::hasColumn('app_settings', 'is_public')) {
                $table->dropColumn('is_public');
            }
        });
    }
};