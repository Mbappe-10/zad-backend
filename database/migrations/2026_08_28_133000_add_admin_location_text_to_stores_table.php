<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stores', 'admin_location_text')) {
            Schema::table('stores', function (Blueprint $table): void {
                $table->string('admin_location_text', 160)
                    ->nullable()
                    ->after('city_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stores', 'admin_location_text')) {
            Schema::table('stores', function (Blueprint $table): void {
                $table->dropColumn('admin_location_text');
            });
        }
    }
};
