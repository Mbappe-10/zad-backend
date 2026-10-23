<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legacy_order_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->uuid('guest_session_id');
            $table->uuid('legacy_guest_session_id');
            $table->char('phone_hash', 64);
            $table->timestamp('verified_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['order_id', 'guest_session_id'], 'legacy_order_grant_unique');
            $table->index('guest_session_id', 'legacy_order_grant_guest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_order_access_grants');
    }
};
