<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('group')->default('general')->index();
            $table->boolean('is_public')->default(true)->index();
            $table->text('description')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('app_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('productive_family_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->json('roles')->nullable();
            $table->string('active_mode')->default('customer');
            $table->timestamps();
        });

        $defaults = [
            ['key'=>'app.name','value'=>json_encode('زاد', JSON_UNESCAPED_UNICODE),'group'=>'branding','description'=>'اسم التطبيق'],
            ['key'=>'app.maintenance','value'=>'false','group'=>'system','description'=>'وضع الصيانة'],
            ['key'=>'app.minimum_version','value'=>json_encode(['android'=>'1.0.0','ios'=>'1.0.0']),'group'=>'system','description'=>'الحد الأدنى للإصدار'],
            ['key'=>'registration.family_enabled','value'=>'true','group'=>'registration','description'=>'فتح التسجيل للأسر المنتجة'],
            ['key'=>'registration.driver_enabled','value'=>'true','group'=>'registration','description'=>'فتح التسجيل للمندوبين'],
            ['key'=>'orders.minimum_amount','value'=>'0','group'=>'orders','description'=>'الحد الأدنى للطلب'],
            ['key'=>'support.contact','value'=>json_encode(['phone'=>null,'whatsapp'=>null,'email'=>null]),'group'=>'support','description'=>'بيانات الدعم'],
            ['key'=>'features','value'=>json_encode(['coupons'=>true,'live_preparation'=>true,'wallets'=>true,'ratings'=>true]),'group'=>'features','description'=>'خصائص التطبيق'],
        ];

        foreach ($defaults as $row) {
            DB::table('app_settings')->insert(array_merge($row, [
                'is_public'=>true,
                'created_at'=>now(),
                'updated_at'=>now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_profiles');
        Schema::dropIfExists('app_settings');
    }
};
