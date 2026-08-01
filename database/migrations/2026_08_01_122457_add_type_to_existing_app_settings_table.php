<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('app_settings') &&
            ! Schema::hasColumn('app_settings', 'type')
        ) {
            Schema::table('app_settings', function (Blueprint $table): void {
                $table
                    ->string('type', 50)
                    ->default('string')
                    ->after('value');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('app_settings') &&
            Schema::hasColumn('app_settings', 'type')
        ) {
            Schema::table('app_settings', function (Blueprint $table): void {
                $table->dropColumn('type');
            });
        }
    }
};