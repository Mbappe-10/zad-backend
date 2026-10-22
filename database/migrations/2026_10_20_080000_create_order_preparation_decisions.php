<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('order_preparation_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders');
            $table->unsignedBigInteger('family_id');
            $table->string('kind', 32); // extension | unable_to_fulfill
            $table->string('status', 32); // pending | accepted | refund_review | expired
            $table->unsignedSmallInteger('extra_minutes')->nullable();
            $table->unsignedInteger('compensation_halalas')->default(0);
            $table->string('compensation_state', 40)->default('none'); // none | manual_review | confirmed
            $table->string('reason', 400);
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamps();
            $table->index(['kind', 'status']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('preparation_extended_until')->nullable();
        });
    }
    public function down(): void {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('preparation_extended_until');
        });
        Schema::dropIfExists('order_preparation_decisions');
    }
};
