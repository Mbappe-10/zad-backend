<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('driver_task_declines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('driver_id');
            $table->timestamps();
            $table->unique(['order_id', 'driver_id'], 'zad_task_decline_unique');
            $table->index('driver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_task_declines');
    }
};
