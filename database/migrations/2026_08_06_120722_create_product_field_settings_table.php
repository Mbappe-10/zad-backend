<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_field_settings', function (Blueprint $table): void {
            $table->id();

            $table->string('field_key', 100)->unique();

            $table->string('label_ar', 150);
            $table->string('label_en', 150)->nullable();

            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_required')->default(false);

            $table->boolean('family_visible')->default(true);
            $table->boolean('family_editable')->default(true);

            $table->boolean('owner_only')->default(false);

            $table->unsignedInteger('sort_order')->default(0);

            $table->json('options')->nullable();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_field_settings');
    }
};