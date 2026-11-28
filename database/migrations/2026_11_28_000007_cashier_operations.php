<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linked stations: packaging batches (with note, place, ready time and an optional
 * small photo), the optional/required supervisor approval, and per-store settings.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('cashier_batches')) {
            Schema::create('cashier_batches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('quantity');
                $table->unsignedInteger('approved_quantity')->nullable();
                $table->string('location', 40)->nullable();
                $table->timestamp('ready_at')->nullable();
                $table->string('note', 300)->nullable();
                $table->string('photo_path')->nullable();
                $table->timestamp('photo_deleted_at')->nullable();
                $table->string('status', 16)->default('pending')->index();
                $table->unsignedBigInteger('packed_by_staff_id')->nullable();
                $table->string('packed_by_name', 60)->nullable();
                $table->unsignedBigInteger('device_id')->nullable();
                $table->unsignedBigInteger('reviewed_by_staff_id')->nullable();
                $table->string('reviewed_by_name', 60)->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('reject_reason', 200)->nullable();
                $table->unsignedBigInteger('production_id')->nullable();
                $table->timestamps();
                $table->index(['store_id', 'created_at']);
            });
        }
        if (Schema::hasTable('cashier_staff') && ! Schema::hasColumn('cashier_staff', 'approval_required')) {
            Schema::table('cashier_staff', function (Blueprint $table): void {
                $table->boolean('approval_required')->default(false);
            });
        }
        if (! Schema::hasTable('cashier_store_settings')) {
            Schema::create('cashier_store_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Additive only.
    }
};
