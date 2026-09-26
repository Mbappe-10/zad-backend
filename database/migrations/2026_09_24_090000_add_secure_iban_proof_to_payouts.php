<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->string('iban_normalized', 24)->nullable()->after('iban')->index();
            $table->boolean('iban_proof_required')->default(false)->after('account_name');
            $table->string('iban_proof_public_id')->nullable()->after('iban_proof_required');
            $table->string('iban_proof_asset_id')->nullable()->after('iban_proof_public_id');
            $table->string('iban_proof_resource_type', 20)->nullable()->after('iban_proof_asset_id');
            $table->string('iban_proof_delivery_type', 20)->nullable()->after('iban_proof_resource_type');
            $table->string('iban_proof_format', 20)->nullable()->after('iban_proof_delivery_type');
            $table->string('iban_proof_original_name')->nullable()->after('iban_proof_format');
            $table->string('iban_proof_mime_type', 100)->nullable()->after('iban_proof_original_name');
            $table->unsignedBigInteger('iban_proof_size')->nullable()->after('iban_proof_mime_type');
            $table->string('iban_proof_sha256', 64)->nullable()->after('iban_proof_size');
            $table->timestamp('iban_proof_uploaded_at')->nullable()->after('iban_proof_sha256');
            $table->timestamp('iban_proof_deleted_at')->nullable()->after('iban_proof_uploaded_at');
            $table->foreignId('iban_proof_deleted_by')->nullable()->after('iban_proof_deleted_at')
                ->constrained('users')->nullOnDelete();
            $table->string('iban_proof_deletion_reason', 500)->nullable()->after('iban_proof_deleted_by');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropForeign(['iban_proof_deleted_by']);
            $table->dropIndex(['iban_normalized']);
            $table->dropColumn([
                'iban_normalized',
                'iban_proof_required',
                'iban_proof_public_id',
                'iban_proof_asset_id',
                'iban_proof_resource_type',
                'iban_proof_delivery_type',
                'iban_proof_format',
                'iban_proof_original_name',
                'iban_proof_mime_type',
                'iban_proof_size',
                'iban_proof_sha256',
                'iban_proof_uploaded_at',
                'iban_proof_deleted_at',
                'iban_proof_deleted_by',
                'iban_proof_deletion_reason',
            ]);
        });
    }
};
