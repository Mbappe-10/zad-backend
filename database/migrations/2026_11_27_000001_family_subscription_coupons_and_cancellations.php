<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription coupons live in their own tables. They never touch order
 * coupons (platform_records / order_coupon_usages) and orders never read them.
 * Every step is guarded so the migration is safe to re-run on production.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('family_subscription_coupons')) {
            Schema::create('family_subscription_coupons', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('name', 120)->nullable();
                $table->string('discount_type', 12); // percentage | fixed
                $table->decimal('discount_value', 10, 2);
                $table->json('plan_codes')->nullable(); // null = every paid plan
                $table->unsignedInteger('max_redemptions')->nullable();
                $table->unsignedInteger('per_family_limit')->default(1);
                $table->unsignedInteger('redeemed_count')->default(0);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('family_subscription_coupon_redemptions')) {
            Schema::create('family_subscription_coupon_redemptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('coupon_id')->constrained('family_subscription_coupons')->cascadeOnDelete();
                $table->foreignId('purchase_id')->unique()->constrained('family_store_purchases')->cascadeOnDelete();
                $table->foreignId('productive_family_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('discount_halalas');
                $table->string('status', 12)->default('reserved'); // reserved | redeemed | released
                $table->timestamps();
                $table->index(['coupon_id', 'status'], 'fsc_redemptions_coupon_status');
                $table->index(['productive_family_id', 'coupon_id'], 'fsc_redemptions_family');
            });
        }

        $purchaseColumns = [
            'original_amount_halalas' => fn (Blueprint $t) => $t->unsignedBigInteger('original_amount_halalas')->nullable(),
            'discount_halalas' => fn (Blueprint $t) => $t->unsignedBigInteger('discount_halalas')->default(0),
            'coupon_id' => fn (Blueprint $t) => $t->unsignedBigInteger('coupon_id')->nullable(),
            'coupon_code' => fn (Blueprint $t) => $t->string('coupon_code', 40)->nullable(),
        ];
        foreach ($purchaseColumns as $column => $define) {
            if (! Schema::hasColumn('family_store_purchases', $column)) {
                Schema::table('family_store_purchases', fn (Blueprint $table) => $define($table));
            }
        }

        $grantColumns = [
            'revocation_reason' => fn (Blueprint $t) => $t->string('revocation_reason', 500)->nullable(),
            'revoked_by_role' => fn (Blueprint $t) => $t->string('revoked_by_role', 20)->nullable(), // owner | family | system
        ];
        foreach ($grantColumns as $column => $define) {
            if (! Schema::hasColumn('family_store_grants', $column)) {
                Schema::table('family_store_grants', fn (Blueprint $table) => $define($table));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('family_subscription_coupon_redemptions');
        Schema::dropIfExists('family_subscription_coupons');
    }
};
