<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_ledger_entries', function (Blueprint $table): void {
            $table->string('status', 30)->default('completed')->after('direction')->index();
            $table->string('counterparty_name')->nullable()->after('currency');
            $table->string('counterparty_email')->nullable()->after('counterparty_name');
            $table->string('payment_method', 80)->nullable()->after('counterparty_email');
            $table->string('external_reference')->nullable()->after('payment_method')->unique();
            $table->timestamp('occurred_at')->nullable()->after('external_reference')->index();
            $table->json('metadata')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('financial_ledger_entries', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropUnique(['external_reference']);
            $table->dropIndex(['occurred_at']);
            $table->dropColumn([
                'status',
                'counterparty_name',
                'counterparty_email',
                'payment_method',
                'external_reference',
                'occurred_at',
                'metadata',
            ]);
        });
    }
};
