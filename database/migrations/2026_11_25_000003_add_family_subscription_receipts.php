<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('family_store_purchases', function (Blueprint $table): void {
            $table->uuid('given_id')->nullable()->unique();
            $table->timestamp('last_checked_at')->nullable();
            $table->uuid('web_intent_id')->nullable()->unique();
            $table->string('web_intent_state', 20)->nullable();
            $table->text('web_client_secret_enc')->nullable();
            $table->string('receipt_number', 50)->nullable()->unique();
            $table->json('receipt_snapshot')->nullable();
            $table->char('receipt_hash', 64)->nullable();
            $table->string('receipt_pdf_path')->nullable();
            $table->char('receipt_pdf_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('family_store_purchases', function (Blueprint $table): void {
            $table->dropColumn(['given_id', 'last_checked_at', 'web_intent_id', 'web_intent_state', 'web_client_secret_enc', 'receipt_number', 'receipt_snapshot', 'receipt_hash', 'receipt_pdf_path', 'receipt_pdf_sha256']);
        });
    }
};
