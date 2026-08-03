<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_controls', function (Blueprint $table) {
            $table->id();

            $table
                ->string('section', 100)
                ->unique();

            $table->json('value');

            $table
                ->string('description', 1000)
                ->nullable();

            $table
                ->boolean('is_sensitive')
                ->default(false);

            $table
                ->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('is_sensitive');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_controls');
    }
};