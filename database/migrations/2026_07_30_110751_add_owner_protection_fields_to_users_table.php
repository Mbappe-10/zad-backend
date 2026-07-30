<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_owner')
                ->default(false)
                ->index();

            $table->boolean('is_protected')
                ->default(false);

            $table->boolean('role_locked')
                ->default(false);

            $table->boolean('permissions_locked')
                ->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_platform_owner',
                'is_protected',
                'role_locked',
                'permissions_locked',
            ]);
        });
    }
};