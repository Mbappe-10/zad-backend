<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique();
            $table->string('module');
            $table->string('action');

            $table->string('name_ar');
            $table->string('name_en');

            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            $table->boolean('is_sensitive')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index([
                'module',
                'action',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
