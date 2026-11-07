<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sponsored_creator_coupons', function (Blueprint $table) {
            $table->string('display_name', 120)->nullable();
            $table->string('code_label', 64)->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('sponsored_creator_coupons', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);

            $table->dropColumn([
                'display_name',
                'code_label',
                'archived_at',
            ]);
        });
    }
};