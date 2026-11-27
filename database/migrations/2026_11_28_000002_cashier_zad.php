<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier Zad: Pro is a separate subscription for any store plan (its price lives in
 * family_store_plans as code cashier_pro and is edited from the dashboard); the basic
 * cashier comes with the gold store plan. Pro time is kept apart from store plan grants.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('family_cashier_grants')) {
            Schema::create('family_cashier_grants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('productive_family_id')->constrained()->cascadeOnDelete();
                $table->string('plan_code', 20)->default('pro');
                $table->string('source', 20); // purchase | owner_grant | launch_offer
                $table->timestamp('starts_at');
                $table->timestamp('ends_at')->index();
                $table->unsignedBigInteger('purchase_id')->nullable()->unique();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('reason', 255)->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedBigInteger('revoked_by')->nullable();
                $table->string('revocation_reason', 255)->nullable();
                $table->timestamps();
                $table->index(['productive_family_id', 'ends_at']);
            });
        }

        if (Schema::hasTable('family_store_plans') && ! DB::table('family_store_plans')->where('code', 'cashier_pro')->exists()) {
            DB::table('family_store_plans')->insert([
                'code' => 'cashier_pro',
                'name' => 'كاشير زاد برو',
                'description' => 'كاشير المصنع المنزلي: دفعات الإنتاج وتكلفتها، طلبات الكميات بالعربون، دفتر الآجل والمساعدات.',
                'price' => 19,
                'currency' => 'SAR',
                'duration_days' => 30,
                'features' => json_encode([], JSON_UNESCAPED_UNICODE),
                'capabilities' => json_encode([
                    'features' => [
                        'quick_sale' => ['basic' => true, 'pro' => true],
                        'daily_stock' => ['basic' => true, 'pro' => true],
                        'day_close' => ['basic' => true, 'pro' => true],
                        'reports' => ['basic' => true, 'pro' => true],
                        'batches' => ['basic' => false, 'pro' => true],
                        'batch_costing' => ['basic' => false, 'pro' => true],
                        'bulk_orders' => ['basic' => false, 'pro' => true],
                        'credit_book' => ['basic' => false, 'pro' => true],
                        'assistants' => ['basic' => false, 'pro' => true],
                        'bazaar_mode' => ['basic' => false, 'pro' => true],
                    ],
                    'available_for' => ['free', 'silver', 'gold'],
                    'launch_offer' => ['enabled' => false, 'plan' => 'gold', 'days' => 90, 'until' => null],
                ]),
                'sort_order' => 90,
                // Selling starts when the owner switches it on from the dashboard.
                'is_visible' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('family_cashier_grants');
    }
};
