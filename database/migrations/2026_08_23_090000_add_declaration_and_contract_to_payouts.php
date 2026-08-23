<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->string('account_role', 30)->nullable()->after('wallet_id')->index();
            $table->foreignId('contract_id')->nullable()->after('account_role')->constrained('role_portal_records')->nullOnDelete();
            $table->foreignId('contract_acceptance_id')->nullable()->after('contract_id')->constrained('role_portal_records')->nullOnDelete();
            $table->string('contract_reference')->nullable()->after('contract_acceptance_id');
            $table->unsignedInteger('contract_version')->nullable()->after('contract_reference');
            $table->string('contract_acceptance_reference')->nullable()->after('contract_version');
            $table->string('contract_document_hash', 64)->nullable()->after('contract_acceptance_reference');
            $table->timestamp('contract_signed_at')->nullable()->after('contract_document_hash');
            $table->string('declaration_reference')->nullable()->unique()->after('contract_signed_at');
            $table->string('declaration_version', 50)->nullable()->after('declaration_reference');
            $table->longText('declaration_signature')->nullable()->after('declaration_version');
            $table->string('declaration_hash', 64)->nullable()->after('declaration_signature');
            $table->timestamp('declaration_signed_at')->nullable()->after('declaration_hash');
            $table->json('policy_snapshot')->nullable()->after('declaration_signed_at');
            $table->json('declaration_snapshot')->nullable()->after('policy_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropForeign(['contract_id']);
            $table->dropForeign(['contract_acceptance_id']);
            $table->dropColumn([
                'account_role', 'contract_id', 'contract_acceptance_id',
                'contract_reference', 'contract_version',
                'contract_acceptance_reference', 'contract_document_hash',
                'contract_signed_at', 'declaration_reference',
                'declaration_version', 'declaration_signature',
                'declaration_hash', 'declaration_signed_at',
                'policy_snapshot', 'declaration_snapshot',
            ]);
        });
    }
};
