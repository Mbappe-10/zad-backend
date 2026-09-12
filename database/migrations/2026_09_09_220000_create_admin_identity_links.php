<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('admin_identity_links')) {
            Schema::create('admin_identity_links', function (Blueprint $table) {
                $table->id();
                $table->string('resource', 16);
                $table->foreignId('record_id')->unique()->constrained('platform_records');
                $table->unsignedBigInteger('entity_id');
                $table->timestamps();
                $table->unique(['resource', 'entity_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_identity_links');
    }
};
