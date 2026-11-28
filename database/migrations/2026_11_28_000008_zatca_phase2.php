<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ZATCA e-invoicing phase 2 (integration with Fatoora): one EGS unit per store
 * (keys, CSIDs, invoice counter and hash chain), the signed XML of every invoice
 * and credit note with its reporting/clearance status, and cashier returns.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('zatca_egs_units')) {
            Schema::create('zatca_egs_units', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('environment', 16);
                $table->string('status', 16)->default('draft'); // draft, active, failed
                $table->string('device_uuid', 40);
                $table->string('vat_number', 15)->nullable();
                $table->string('seller_name', 120)->nullable();
                $table->string('branch_name', 80)->nullable();
                $table->string('id_type', 8)->default('CRN');
                $table->string('id_number', 30)->nullable();
                $table->string('street', 100)->nullable();
                $table->string('building_number', 10)->nullable();
                $table->string('district', 80)->nullable();
                $table->string('city', 60)->nullable();
                $table->string('postal_code', 10)->nullable();
                $table->text('private_key')->nullable();
                $table->text('csr')->nullable();
                $table->text('compliance_certificate')->nullable();
                $table->text('compliance_secret')->nullable();
                $table->string('compliance_request_id', 60)->nullable();
                $table->text('certificate')->nullable();
                $table->text('secret')->nullable();
                $table->string('production_request_id', 60)->nullable();
                $table->timestamp('certificate_expires_at')->nullable();
                $table->unsignedBigInteger('icv')->default(0);
                $table->text('last_hash')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('onboarded_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('sale_returns')) {
            Schema::create('sale_returns', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sale_receipt_id')->constrained()->cascadeOnDelete();
                $table->string('number', 32)->nullable();
                $table->string('public_token', 40)->unique();
                $table->json('items');
                $table->decimal('subtotal', 12, 2);
                $table->decimal('discount', 12, 2)->default(0);
                $table->decimal('vat_amount', 12, 2)->default(0);
                $table->decimal('total', 12, 2);
                $table->string('reason', 200);
                $table->string('refund_method', 20);
                $table->boolean('restocked')->default(false);
                $table->unsignedBigInteger('cashier_staff_id')->nullable();
                $table->string('staff_name', 60)->nullable();
                $table->timestamp('issued_at');
                $table->timestamps();
                $table->index(['store_id', 'issued_at']);
            });
        }
        if (! Schema::hasTable('zatca_documents')) {
            Schema::create('zatca_documents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->foreignId('egs_unit_id')->constrained('zatca_egs_units')->cascadeOnDelete();
                $table->foreignId('sale_receipt_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('sale_return_id')->nullable()->constrained()->nullOnDelete();
                $table->string('kind', 8); // invoice, credit
                $table->string('invoice_type', 12); // simplified, standard
                $table->unsignedBigInteger('icv');
                $table->string('uuid', 40)->unique();
                $table->string('number', 32);
                $table->text('invoice_hash');
                $table->text('previous_hash');
                $table->longText('signed_xml');
                $table->text('qr');
                $table->string('status', 16)->default('pending')->index(); // pending, reported, cleared, rejected
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->json('warnings')->nullable();
                $table->json('errors')->nullable();
                $table->timestamps();
                $table->unique(['egs_unit_id', 'icv']);
            });
        }
        if (Schema::hasTable('sale_receipts')) {
            Schema::table('sale_receipts', function (Blueprint $table): void {
                foreach (['customer_street' => 100, 'customer_building' => 10, 'customer_district' => 80, 'customer_city' => 60, 'customer_postal' => 10] as $col => $len) {
                    if (! Schema::hasColumn('sale_receipts', $col)) {
                        $table->string($col, $len)->nullable();
                    }
                }
                if (! Schema::hasColumn('sale_receipts', 'returned_total')) {
                    $table->decimal('returned_total', 12, 2)->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_documents');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('zatca_egs_units');
    }
};
