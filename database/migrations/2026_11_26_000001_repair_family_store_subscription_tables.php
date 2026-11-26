<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production recorded the 2026_11_25 family-store migrations as run while the
 * tables were never created ("relation family_store_grants does not exist").
 * This repair creates only what is missing, so it is safe on databases where
 * the original migrations did run.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('family_store_plans')) {
            Schema::create('family_store_plans', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 20)->unique();
                $table->string('name', 100);
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->string('currency', 3)->default('SAR');
                $table->unsignedInteger('duration_days')->default(30);
                $table->json('features');
                $table->json('capabilities');
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_visible')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('family_store_grants')) {
            Schema::create('family_store_grants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('productive_family_id')->constrained()->cascadeOnDelete();
                $table->string('plan_code', 20);
                $table->string('source', 20)->default('owner_grant');
                $table->timestamp('starts_at');
                $table->timestamp('ends_at');
                $table->timestamp('revoked_at')->nullable();
                $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('reason')->nullable();
                $table->timestamps();
                $table->index(['productive_family_id', 'revoked_at', 'starts_at', 'ends_at'], 'family_grants_effective');
            });
        }

        $storeColumns = [
            'storefront_design' => fn (Blueprint $t) => $t->json('storefront_design')->nullable(),
            'family_logo_changes' => fn (Blueprint $t) => $t->unsignedInteger('family_logo_changes')->default(0),
            'family_name_changed_at' => fn (Blueprint $t) => $t->timestamp('family_name_changed_at')->nullable(),
        ];
        foreach ($storeColumns as $column => $define) {
            if (! Schema::hasColumn('stores', $column)) {
                Schema::table('stores', fn (Blueprint $table) => $define($table));
            }
        }

        if (! Schema::hasTable('family_store_purchases')) {
            Schema::create('family_store_purchases', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('productive_family_id')->constrained()->cascadeOnDelete();
                $table->string('plan_code', 20);
                $table->unsignedBigInteger('amount_halalas');
                $table->unsignedInteger('duration_days');
                $table->string('currency', 3)->default('SAR');
                $table->string('mode', 10);
                $table->string('status', 20)->default('creating');
                $table->uuid('invoice_id')->nullable()->unique();
                $table->text('checkout_url')->nullable();
                $table->string('callback_token_hash', 64)->unique();
                $table->uuid('provider_payment_id')->nullable()->unique();
                $table->timestamp('expires_at');
                $table->timestamp('paid_at')->nullable();
                $table->foreignId('grant_id')->nullable()->unique()->constrained('family_store_grants')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['productive_family_id', 'status', 'created_at'], 'family_purchase_latest');
            });
        }

        $purchaseColumns = [
            'given_id' => fn (Blueprint $t) => $t->uuid('given_id')->nullable()->unique(),
            'last_checked_at' => fn (Blueprint $t) => $t->timestamp('last_checked_at')->nullable(),
            'web_intent_id' => fn (Blueprint $t) => $t->uuid('web_intent_id')->nullable()->unique(),
            'web_intent_state' => fn (Blueprint $t) => $t->string('web_intent_state', 20)->nullable(),
            'web_client_secret_enc' => fn (Blueprint $t) => $t->text('web_client_secret_enc')->nullable(),
            'receipt_number' => fn (Blueprint $t) => $t->string('receipt_number', 50)->nullable()->unique(),
            'receipt_snapshot' => fn (Blueprint $t) => $t->json('receipt_snapshot')->nullable(),
            'receipt_hash' => fn (Blueprint $t) => $t->char('receipt_hash', 64)->nullable(),
            'receipt_pdf_path' => fn (Blueprint $t) => $t->string('receipt_pdf_path')->nullable(),
            'receipt_pdf_sha256' => fn (Blueprint $t) => $t->char('receipt_pdf_sha256', 64)->nullable(),
        ];
        foreach ($purchaseColumns as $column => $define) {
            if (! Schema::hasColumn('family_store_purchases', $column)) {
                Schema::table('family_store_purchases', fn (Blueprint $table) => $define($table));
            }
        }

        $this->seedPlans();
    }

    public function down(): void
    {
        // Repair only: rolling back must not drop tables the original migrations own.
    }

    private function seedPlans(): void
    {
        $now = now();
        $plans = [
            [
                'code' => 'free', 'name' => 'المجانية', 'description' => 'متجر أساسي للأسر المنتجة',
                'price' => 0, 'duration_days' => 0, 'sort_order' => 0,
                'features' => [['id' => 'catalog', 'title' => 'المنتجات والطلبات'], ['id' => 'logo_once', 'title' => 'تغيير الشعار مرة واحدة'], ['id' => 'share', 'title' => 'رابط مشاركة المتجر']],
                'capabilities' => ['banner' => false, 'themes' => false, 'fonts' => false, 'product_cards' => false, 'rename_store' => false, 'weekly_promotions' => 0, 'coupons' => false],
            ],
            [
                'code' => 'silver', 'name' => 'الفضية', 'description' => 'هوية متجر وترويج أسبوعي',
                'price' => 49, 'duration_days' => 30, 'sort_order' => 1,
                'features' => [['id' => 'banner', 'title' => 'بنر للمتجر'], ['id' => 'theme', 'title' => 'ألوان وخطوط'], ['id' => 'promotion', 'title' => 'ترويج منتجين أسبوعياً (قريباً)']],
                'capabilities' => ['banner' => true, 'themes' => true, 'fonts' => true, 'product_cards' => false, 'rename_store' => false, 'weekly_promotions' => 2, 'coupons' => false],
            ],
            [
                'code' => 'gold', 'name' => 'الذهبية', 'description' => 'تخصيص كامل للمتجر وترويج متقدم',
                'price' => 99, 'duration_days' => 30, 'sort_order' => 2,
                'features' => [['id' => 'banner', 'title' => 'بنر للمتجر'], ['id' => 'cards', 'title' => 'تصميم بطاقات المنتجات'], ['id' => 'promotion', 'title' => 'ترويج أربعة منتجات أسبوعياً (قريباً)'], ['id' => 'coupons', 'title' => 'طلب كوبون بموافقة الإدارة (قريباً)'], ['id' => 'rename', 'title' => 'تغيير اسم المتجر كل 30 يوماً']],
                'capabilities' => ['banner' => true, 'themes' => true, 'fonts' => true, 'product_cards' => true, 'rename_store' => true, 'weekly_promotions' => 4, 'coupons' => true],
            ],
        ];

        foreach ($plans as $plan) {
            // Never overwrite prices or features the owner already edited from the dashboard.
            if (DB::table('family_store_plans')->where('code', $plan['code'])->exists()) {
                continue;
            }
            DB::table('family_store_plans')->insert([
                ...$plan,
                'features' => json_encode($plan['features'], JSON_UNESCAPED_UNICODE),
                'capabilities' => json_encode($plan['capabilities']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
