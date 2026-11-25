<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
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

        Schema::table('stores', function (Blueprint $table): void {
            $table->json('storefront_design')->nullable();
            $table->unsignedInteger('family_logo_changes')->default(0);
            $table->timestamp('family_name_changed_at')->nullable();
        });

        $now = now();
        DB::table('family_store_plans')->insert([
            [
                'code' => 'free', 'name' => 'المجانية', 'description' => 'متجر أساسي للأسر المنتجة',
                'price' => 0, 'duration_days' => 0, 'sort_order' => 0,
                'features' => json_encode([['id' => 'catalog', 'title' => 'المنتجات والطلبات'], ['id' => 'logo_once', 'title' => 'تغيير الشعار مرة واحدة'], ['id' => 'share', 'title' => 'رابط مشاركة المتجر']], JSON_UNESCAPED_UNICODE),
                'capabilities' => json_encode(['banner' => false, 'themes' => false, 'fonts' => false, 'product_cards' => false, 'rename_store' => false, 'weekly_promotions' => 0, 'coupons' => false]),
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'silver', 'name' => 'الفضية', 'description' => 'هوية متجر وترويج أسبوعي',
                'price' => 49, 'duration_days' => 30, 'sort_order' => 1,
                'features' => json_encode([['id' => 'banner', 'title' => 'بنر للمتجر'], ['id' => 'theme', 'title' => 'ألوان وخطوط'], ['id' => 'promotion', 'title' => 'ترويج منتجين أسبوعياً (قريباً)']], JSON_UNESCAPED_UNICODE),
                'capabilities' => json_encode(['banner' => true, 'themes' => true, 'fonts' => true, 'product_cards' => false, 'rename_store' => false, 'weekly_promotions' => 2, 'coupons' => false]),
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'gold', 'name' => 'الذهبية', 'description' => 'تخصيص كامل للمتجر وترويج متقدم',
                'price' => 99, 'duration_days' => 30, 'sort_order' => 2,
                'features' => json_encode([['id' => 'banner', 'title' => 'بنر للمتجر'], ['id' => 'cards', 'title' => 'تصميم بطاقات المنتجات'], ['id' => 'promotion', 'title' => 'ترويج أربعة منتجات أسبوعياً (قريباً)'], ['id' => 'coupons', 'title' => 'طلب كوبون بموافقة الإدارة (قريباً)'], ['id' => 'rename', 'title' => 'تغيير اسم المتجر كل 30 يوماً']], JSON_UNESCAPED_UNICODE),
                'capabilities' => json_encode(['banner' => true, 'themes' => true, 'fonts' => true, 'product_cards' => true, 'rename_store' => true, 'weekly_promotions' => 4, 'coupons' => true]),
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['storefront_design', 'family_logo_changes', 'family_name_changed_at']);
        });
        Schema::dropIfExists('family_store_grants');
        Schema::dropIfExists('family_store_plans');
    }
};
