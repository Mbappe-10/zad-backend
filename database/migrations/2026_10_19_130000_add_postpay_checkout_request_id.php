<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('orders', function (Blueprint $table): void {
            $table->uuid('checkout_request_id')->nullable();
            $table->unique(['guest_session_id', 'checkout_request_id'], 'orders_guest_checkout_idempotency');
        });
    }
    public function down(): void {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_guest_checkout_idempotency');
            $table->dropColumn('checkout_request_id');
        });
    }
};
