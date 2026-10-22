<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Existing orders keep NULL: legacy, already passed the old gate.
            $table->string('contact_verification_state', 20)->nullable()->index();
            $table->string('pending_contact_phone', 20)->nullable();
            $table->timestamp('contact_verified_at')->nullable();
            $table->timestamp('contact_released_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['contact_verification_state']);
            $table->dropColumn([
                'contact_verification_state', 'pending_contact_phone',
                'contact_verified_at', 'contact_released_at',
            ]);
        });
    }
};
