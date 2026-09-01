<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zad_payment_attempts')) {
            return;
        }

        Schema::create('zad_payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('gateway', 30)->default('moyasar')->index();
            $table->string('provider_payment_id', 100)->nullable()->unique();
            $table->string('status', 30)->default('created')->index();
            $table->unsignedBigInteger('amount_halalas');
            $table->string('currency', 3)->default('SAR');
            $table->text('failure_message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zad_payment_attempts');
    }
};