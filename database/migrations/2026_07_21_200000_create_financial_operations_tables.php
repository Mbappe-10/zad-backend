<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->decimal('fixed_fee', 12, 2)->default(0);
            $table->decimal('percentage_fee', 7, 4)->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('payment_providers')->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('provider_reference')->nullable()->index();
            $table->string('method')->nullable();
            $table->string('currency', 3)->default('SAR');
            $table->decimal('gross_amount', 14, 2);
            $table->decimal('provider_fee', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2);
            $table->string('status')->default('pending')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('commission_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('beneficiary_type')->index();
            $table->string('calculation_type')->default('percentage');
            $table->decimal('value', 14, 4);
            $table->decimal('minimum_amount', 14, 2)->nullable();
            $table->decimal('maximum_amount', 14, 2)->nullable();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('vehicle_type')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commission_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('beneficiary_type');
            $table->unsignedBigInteger('beneficiary_id')->nullable();
            $table->decimal('base_amount', 14, 2);
            $table->decimal('commission_amount', 14, 2);
            $table->string('status')->default('pending')->index();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'beneficiary_type', 'beneficiary_id'], 'order_commission_unique');
        });

        Schema::create('payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('reference')->unique();
            $table->decimal('amount', 14, 2);
            $table->decimal('fee', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2);
            $table->string('status')->default('pending')->index();
            $table->string('bank_name')->nullable();
            $table->string('iban')->nullable();
            $table->string('account_name')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('pending')->index();
            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('financial_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_number')->unique();
            $table->date('entry_date')->index();
            $table->string('account_code')->index();
            $table->string('direction');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('SAR');
            $table->nullableMorphs('source');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['financial_ledger_entries', 'refunds', 'payouts', 'order_commissions', 'commission_rules', 'payments', 'payment_providers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
