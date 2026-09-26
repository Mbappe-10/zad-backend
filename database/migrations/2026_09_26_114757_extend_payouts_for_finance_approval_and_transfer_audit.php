<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            // مرحلة المراجعة المالية الأولى
            $table->text('approval_notes')->nullable()->after('approved_at');
            $table->text('rejection_reason')->nullable()->after('approval_notes');
            $table->timestamp('rejected_at')->nullable()->after('rejection_reason');
            $table->foreignId('rejected_by')->nullable()->after('rejected_at')
                ->constrained('users')->nullOnDelete();

            // مرحلة تنفيذ التحويل - مستقلة عن الاعتماد
            $table->foreignId('processing_by')->nullable()->after('rejected_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('processing_at')->nullable()->after('processing_by');

            $table->foreignId('executed_by')->nullable()->after('processing_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable()->after('executed_by');

            // بيانات التحويل البنكي الفعلي
            $table->string('bank_transfer_reference', 150)->nullable()
                ->after('executed_at')->index();
            $table->text('transfer_notes')->nullable()
                ->after('bank_transfer_reference');

            // إثبات تنفيذ التحويل
            $table->string('transfer_proof_public_id')->nullable()
                ->after('transfer_notes');
            $table->string('transfer_proof_asset_id')->nullable()
                ->after('transfer_proof_public_id');
            $table->string('transfer_proof_resource_type', 20)->nullable()
                ->after('transfer_proof_asset_id');
            $table->string('transfer_proof_delivery_type', 20)->nullable()
                ->after('transfer_proof_resource_type');
            $table->string('transfer_proof_format', 20)->nullable()
                ->after('transfer_proof_delivery_type');
            $table->string('transfer_proof_original_name')->nullable()
                ->after('transfer_proof_format');
            $table->string('transfer_proof_mime_type', 100)->nullable()
                ->after('transfer_proof_original_name');
            $table->unsignedBigInteger('transfer_proof_size')->nullable()
                ->after('transfer_proof_mime_type');
            $table->string('transfer_proof_sha256', 64)->nullable()
                ->after('transfer_proof_size');
            $table->timestamp('transfer_proof_uploaded_at')->nullable()
                ->after('transfer_proof_sha256');

            // فهرس مناسب لطابور موظفي المالية
            $table->index(
                ['status', 'approved_at'],
                'payouts_finance_queue_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropIndex('payouts_finance_queue_index');
            $table->dropIndex(['bank_transfer_reference']);

            $table->dropForeign(['rejected_by']);
            $table->dropForeign(['processing_by']);
            $table->dropForeign(['executed_by']);

            $table->dropColumn([
                'approval_notes',
                'rejection_reason',
                'rejected_at',
                'rejected_by',
                'processing_by',
                'processing_at',
                'executed_by',
                'executed_at',
                'bank_transfer_reference',
                'transfer_notes',
                'transfer_proof_public_id',
                'transfer_proof_asset_id',
                'transfer_proof_resource_type',
                'transfer_proof_delivery_type',
                'transfer_proof_format',
                'transfer_proof_original_name',
                'transfer_proof_mime_type',
                'transfer_proof_size',
                'transfer_proof_sha256',
                'transfer_proof_uploaded_at',
            ]);
        });
    }
};
