<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('phone_verifications', function (Blueprint $table): void {
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->index(['order_id','status'], 'phone_verifications_order_status_idx');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedTinyInteger('contact_phone_edit_count')->default(0);
        });
    }
    public function down(): void {
        Schema::table('phone_verifications', function (Blueprint $table): void {
            $table->dropIndex('phone_verifications_order_status_idx');
            $table->dropConstrainedForeignId('order_id');
        });
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('contact_phone_edit_count'));
    }
};
