<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('productive_family_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();

            $table->string('currency', 3)->default('SAR');
            $table->decimal('order_total', 14, 2)->default(0);
            $table->decimal('family_gross', 14, 2)->default(0);
            $table->decimal('family_commission', 14, 2)->default(0);
            $table->decimal('family_net', 14, 2)->default(0);
            $table->decimal('driver_gross', 14, 2)->default(0);
            $table->decimal('driver_commission', 14, 2)->default(0);
            $table->decimal('driver_net', 14, 2)->default(0);
            $table->decimal('platform_total', 14, 2)->default(0);

            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('release_due_at')->nullable()->index();
            $table->timestamp('released_at')->nullable()->index();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('hold_reason')->nullable();
            $table->json('calculation_snapshot')->nullable();

            $table->foreignId('family_wallet_transaction_id')
                ->nullable()
                ->constrained('wallet_transactions')
                ->nullOnDelete();

            $table->foreignId('driver_wallet_transaction_id')
                ->nullable()
                ->constrained('wallet_transactions')
                ->nullOnDelete();

            $table->timestamps();
            $table->index(['driver_id', 'status', 'released_at']);
            $table->index(['productive_family_id', 'status', 'released_at'], 'settlements_family_status_released_index');
        });

        $settings = [
            [
                'key' => 'settlements.release_delay_hours',
                'value' => json_encode(24),
                'type' => 'integer',
                'group' => 'settlements',
                'is_public' => false,
                'description' => 'عدد الساعات قبل تحويل أرباح الطلب من الرصيد المعلق إلى المتاح',
            ],
            [
                'key' => 'settlements.family_commission_percentage',
                'value' => json_encode(0),
                'type' => 'decimal',
                'group' => 'settlements',
                'is_public' => false,
                'description' => 'نسبة عمولة المنصة الافتراضية من صافي منتجات الأسرة',
            ],
            [
                'key' => 'settlements.driver_commission_percentage',
                'value' => json_encode(0),
                'type' => 'decimal',
                'group' => 'settlements',
                'is_public' => false,
                'description' => 'نسبة عمولة المنصة الافتراضية من أجرة التوصيل',
            ],
            [
                'key' => 'settlements.automatic_release_enabled',
                'value' => json_encode(true),
                'type' => 'boolean',
                'group' => 'settlements',
                'is_public' => false,
                'description' => 'تفعيل تحرير التسويات المستحقة تلقائيًا',
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $setting['key']],
                [...$setting, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_settlements');

        DB::table('app_settings')
            ->whereIn('key', [
                'settlements.release_delay_hours',
                'settlements.family_commission_percentage',
                'settlements.driver_commission_percentage',
                'settlements.automatic_release_enabled',
            ])
            ->delete();
    }
};
